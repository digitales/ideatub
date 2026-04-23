<?php

namespace App\Events;

use App\Models\ImportBatchFile;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ImportFileProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ImportBatchFile $file) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('import.'.$this->file->import_batch_id)];
    }

    public function broadcastWith(): array
    {
        return [
            'file_id' => $this->file->id,
            'relative_path' => $this->file->relative_path,
            'status' => $this->file->status,
            'thought_id' => $this->file->thought_id,
            'error_code' => $this->file->error_code,
            'error_message' => $this->file->error_message,
        ];
    }
}
