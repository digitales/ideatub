<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Services\Import\ImportStagingStore;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneExpiredImportBatchesCommand extends Command
{
    protected $signature = 'imports:prune-expired-batches {--days=30 : Retention window in days}';

    protected $description = 'Delete ImportBatches + files + staged bytes older than --days.';

    public function handle(ImportStagingStore $staging): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be >= 1');

            return self::FAILURE;
        }
        $cutoff = Carbon::now()->subDays($days);

        ImportBatch::query()
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunk(50, function ($batches) use ($staging): void {
                foreach ($batches as $batch) {
                    $staging->deleteBatchDir($batch);
                    $batch->delete();
                }
            });

        return self::SUCCESS;
    }
}
