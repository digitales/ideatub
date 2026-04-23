<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Models\ImportBatchFile;
use App\Services\Import\MicrositeImportService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMicrositeImportBatch implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public string $batchId) {}

    public function handle(MicrositeImportService $import): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch === null) {
            return;
        }
        if ($this->batch() !== null && $this->batch()->cancelled()) {
            return;
        }

        if (data_get($batch->options, 'import_kind') !== 'microsite') {
            return;
        }
        if ($batch->file_count > 0
            && $batch->files()->where('status', ImportBatchFile::STATUS_DONE)->count() === (int) $batch->file_count) {
            return;
        }

        $import->process($batch);
    }
}
