<?php

namespace App\Events;

use App\Models\Thought;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IdeaResearchRequested
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Thought $idea,
        public string $source
    ) {}
}
