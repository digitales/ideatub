<?php

namespace App\Jobs;

use App\Events\ImportBatchCompleted;
use App\Models\ImportBatch;
use App\Models\ImportBatchFile;
use App\Services\Import\ImportCompletionNotifier;
use App\Services\Import\ImportStagingStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinaliseImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $batchId) {}

    public function handle(ImportCompletionNotifier $notifier, ImportStagingStore $staging): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch === null) {
            return;
        }

        $counts = $batch->files()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $batch->forceFill([
            'processed_count' => (int) ($counts[ImportBatchFile::STATUS_DONE] ?? 0),
            'failed_count' => (int) ($counts[ImportBatchFile::STATUS_FAILED] ?? 0),
            'skipped_count' => (int) (($counts[ImportBatchFile::STATUS_SKIPPED_DUPLICATE] ?? 0)
                + ($counts[ImportBatchFile::STATUS_SKIPPED_UNSUPPORTED] ?? 0)),
            'status' => ($counts[ImportBatchFile::STATUS_FAILED] ?? 0) > 0
                ? ImportBatch::STATUS_COMPLETED_WITH_FAILURES
                : ImportBatch::STATUS_COMPLETED,
        ])->save();

        $staging->deleteBatchDir($batch);

        event(new ImportBatchCompleted($batch->fresh()));
        $notifier->notify($batch->fresh());
    }
}
