<?php

namespace App\Observers;

use App\Jobs\ComputeSemanticLinkSuggestionsJob;
use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Jobs\WorkingMemoryRebuildJob;
use App\Models\Project;
use App\Models\Thought;
use App\Services\WorkingMemory\WorkingMemoryExternalGuard;

class ThoughtObserver
{
    public function created(Thought $thought): void
    {
        if ($thought->user_id === null) {
            return;
        }

        if ($this->isMeetingThought($thought)) {
            SynthesizeMeetingCompactionJob::dispatch($thought->id);
            $this->dispatchRefreshWithMeetingDelay($thought);

            return;
        }

        RefreshWorkingMemoryIncremental::dispatch($thought->id);
        $this->dispatchAutoRebuildForThought($thought);
        $this->dispatchLinkSuggestions($thought);
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
            $this->dispatchRefreshWithMeetingDelay($thought);

            return;
        }

        RefreshWorkingMemoryIncremental::dispatch($thought->id);
        $this->dispatchAutoRebuildForThought($thought);

        if ($thought->wasChanged(['content'])) {
            $this->dispatchLinkSuggestions($thought);
        }
    }

    private function isMeetingThought(Thought $thought): bool
    {
        return data_get($thought->metadata, 'type') === 'meeting';
    }

    /**
     * Race-avoidance: the meeting compaction job and the incremental refresh both run
     * async. If refresh runs first, the new compaction:meeting version is missing from
     * the evidence pack and won't be cited until the next refresh trigger. Delaying the
     * refresh gives the compaction a head start so it's persisted by the time refresh
     * builds the evidence pack.
     *
     * The delay is configurable; 0 disables it (useful for sync queues and deterministic
     * tests).
     */
    private function dispatchRefreshWithMeetingDelay(Thought $thought): void
    {
        $delaySeconds = max(0, (int) config('working_memory.meeting_refresh_delay_seconds', 60));

        $dispatch = RefreshWorkingMemoryIncremental::dispatch($thought->id);

        if ($delaySeconds > 0) {
            $dispatch->delay(now()->addSeconds($delaySeconds));
        }

        $this->dispatchAutoRebuildForThought($thought);
    }

    private function dispatchAutoRebuildForThought(Thought $thought): void
    {
        $thought->loadMissing('projects:id');

        foreach ($thought->projects as $project) {
            if ($this->shouldSkipAutoRebuildForProject($project, (int) $thought->user_id)) {
                continue;
            }

            WorkingMemoryRebuildJob::dispatch((string) $project->id);
        }
    }

    private function shouldSkipAutoRebuildForProject(Project $project, int $userId): bool
    {
        return app(WorkingMemoryExternalGuard::class)->shouldSkipConsolidatedBuild(
            $userId,
            'project',
            (string) $project->id,
            false,
        );
    }

    private function dispatchLinkSuggestions(Thought $thought): void
    {
        if (! config('features.memory_graph_suggestions')) {
            return;
        }

        ComputeSemanticLinkSuggestionsJob::dispatch($thought->id);
    }
}
