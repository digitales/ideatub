<?php

namespace App\Events;

use App\Models\ImportBatch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ImportBatchCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ImportBatch $batch) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('import.'.$this->batch->id)];
    }

    public function broadcastWith(): array
    {
        return [
            'batch_id' => $this->batch->id,
            'status' => $this->batch->status,
            'processed_count' => $this->batch->processed_count,
            'failed_count' => $this->batch->failed_count,
            'skipped_count' => $this->batch->skipped_count,
        ];
    }
}
