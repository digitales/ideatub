<?php

namespace App\Events;

use App\Models\ResearchRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Optional domain event for observability; idea research is queued via {@see \App\Jobs\RunResearchRun}.
 */
class IdeaResearchRequested
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ResearchRun $run
    ) {}
}
