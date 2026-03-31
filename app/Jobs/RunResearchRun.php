<?php

namespace App\Jobs;

use App\Models\ResearchRun;
use App\Services\Research\ResearchWorkflowRunner;
use App\Services\ResearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunResearchRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 600;

    public function __construct(
        public readonly int $researchRunId
    ) {}

    public function handle(ResearchWorkflowRunner $workflowRunner, ResearchService $researchService): void
    {
        $run = ResearchRun::query()->find($this->researchRunId);
        if ($run === null) {
            return;
        }

        $ideaThoughtId = $run->idea_thought_id;

        try {
            $workflowRunner->run($run->fresh());
        } finally {
            $researchService->clearResearchPendingForIdeaThought($ideaThoughtId);
        }
    }
}
