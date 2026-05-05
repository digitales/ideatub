<?php

namespace App\Observers;

use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Models\Thought;

class ThoughtObserver
{
    public function created(Thought $thought): void
    {
        if ($thought->user_id === null) {
            return;
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

        RefreshWorkingMemoryIncremental::dispatch($thought->id);
    }
}
