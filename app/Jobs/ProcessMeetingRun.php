<?php

namespace App\Jobs;

use App\Models\MeetingRun;
use App\Services\Meetings\MeetingWorkflowRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMeetingRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 600;

    public function __construct(
        public readonly int $meetingRunId
    ) {}

    public function handle(MeetingWorkflowRunner $workflowRunner): void
    {
        $run = MeetingRun::query()->find($this->meetingRunId);
        if ($run === null) {
            return;
        }

        $workflowRunner->run($run->fresh());
    }
}
