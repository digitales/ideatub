<?php

namespace App\Jobs;

use App\Events\ImportFileProcessed;
use App\Models\ImportBatchFile;
use App\Services\Import\FileImportService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessImportFile implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $fileId) {}

    public function handle(FileImportService $service): void
    {
        $row = ImportBatchFile::query()->find($this->fileId);
        if ($row === null) {
            return;
        }
        if ($this->batch() !== null && $this->batch()->cancelled()) {
            $row->update(['status' => ImportBatchFile::STATUS_CANCELLED]);
            event(new ImportFileProcessed($row->fresh()));

            return;
        }

        $service->process($row);
        event(new ImportFileProcessed($row->fresh()));
    }
}
