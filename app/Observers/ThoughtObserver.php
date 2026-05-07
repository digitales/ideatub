<?php

namespace App\Observers;

use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Models\Thought;

class ThoughtObserver
{
    public function created(Thought $thought): void
    {
        if ($thought->user_id === null) {
            return;
        }

        if ($this->isMeetingThought($thought)) {
            SynthesizeMeetingCompactionJob::dispatch($thought->id);
        }

        RefreshWorkingMemoryIncremental::dispatch($thought->id);
    }

    public function updated(Thought $thought): void
    {
        if ($thought->user_id === null) {
            return;
        }

        if (! $thought->wasChanged([
            'content',
            'metadata',
            'source',
            'source_metadata',
            'parent_id',
            'is_visible_in_stream',
            'visibility_reason',
        ])) {
            return;
        }

        if ($this->isMeetingThought($thought) && $thought->wasChanged(['content', 'metadata'])) {
            SynthesizeMeetingCompactionJob::dispatch($thought->id);
        }

        RefreshWorkingMemoryIncremental::dispatch($thought->id);
    }

    private function isMeetingThought(Thought $thought): bool
    {
        return data_get($thought->metadata, 'type') === 'meeting';
    }
}
