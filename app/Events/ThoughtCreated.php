<?php

namespace App\Events;

use App\Models\Thought;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ThoughtCreated implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Thought $thought
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channel = $this->thought->user_id
            ? new PrivateChannel('App.Models.User.'.$this->thought->user_id)
            : null;

        return $channel ? [$channel] : [];
    }

    /**
     * The event's broadcast name (Echo listens for .ThoughtCreated).
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'ThoughtCreated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'thought_id' => $this->thought->id,
            'user_id' => $this->thought->user_id,
            'parent_id' => $this->thought->parent_id,
            'metadata' => $this->thought->metadata ?? [],
        ];
    }
}
