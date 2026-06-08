<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\WorkingMemory\WorkingMemoryAutoRebuildService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkingMemoryRebuildJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $projectId,
    ) {}

    public function uniqueId(): string
    {
        return 'wm-auto-rebuild:'.$this->projectId;
    }

    public function handle(WorkingMemoryAutoRebuildService $rebuildService): void
    {
        $project = Project::query()->find($this->projectId);
        if ($project === null) {
            Log::info('WorkingMemoryRebuildJob skipped: project not found.', [
                'project_id' => $this->projectId,
            ]);

            return;
        }

        $rebuildService->rebuild($project);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('WorkingMemoryRebuildJob failed permanently.', [
            'project_id' => $this->projectId,
            'message' => $exception->getMessage(),
        ]);
    }
}
