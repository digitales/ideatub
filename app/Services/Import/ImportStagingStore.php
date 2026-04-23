<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportBatchFile;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImportStagingStore
{
    public function store(UploadedFile $file, ImportBatch $batch, ImportBatchFile $row): string
    {
        $path = $batch->staging_path.'/'.$row->id;
        Storage::disk('local')->put($path, $file->get());

        return $path;
    }

    public function readStaged(ImportBatch $batch, ImportBatchFile $row): string
    {
        $path = $batch->staging_path.'/'.$row->id;
        if (! Storage::disk('local')->exists($path)) {
            return '';
        }

        return (string) Storage::disk('local')->get($path);
    }

    public function deleteStaged(ImportBatch $batch, ImportBatchFile $row): void
    {
        $path = $batch->staging_path.'/'.$row->id;
        Storage::disk('local')->delete($path);
    }

    public function deleteBatchDir(ImportBatch $batch): void
    {
        Storage::disk('local')->deleteDirectory($batch->staging_path);
    }

    /**
     * @return int Number of batch staging directories removed.
     */
    public function pruneExpiredBatches(DateTimeInterface $olderThan): int
    {
        $removed = 0;
        ImportBatch::query()
            ->where('updated_at', '<', $olderThan)
            ->chunkById(50, function ($batches) use (&$removed): void {
                foreach ($batches as $batch) {
                    if (Storage::disk('local')->exists($batch->staging_path)) {
                        Storage::disk('local')->deleteDirectory($batch->staging_path);
                        $removed++;
                    }
                }
            });

        return $removed;
    }
}
