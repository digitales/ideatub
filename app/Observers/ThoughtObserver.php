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
}
