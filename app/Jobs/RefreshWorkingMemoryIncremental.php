<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Models\WorkingMemory;
use App\Services\WorkingMemory\WorkingMemoryScopeResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves affected working-memory scopes for a thought and dispatches one
 * {@see RefreshWorkingMemoryIncrementalScope} job per scope so each compose call
 * has its own queue timeout budget.
 */
class RefreshWorkingMemoryIncremental implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /** Fan-out only: scope jobs perform OpenRouter compose work. */
    public int $timeout = 120;

    public function __construct(
        private readonly string $thoughtId
    ) {}

    public function handle(WorkingMemoryScopeResolver $scopeResolver): void
    {
        $thought = Thought::query()->with('projects:id')->find($this->thoughtId);
        if (! $thought instanceof Thought || $thought->user_id === null) {
            return;
        }

        $userId = (int) $thought->user_id;

        foreach ($scopeResolver->forThought($thought) as $scope) {
            RefreshWorkingMemoryIncrementalScope::dispatch(
                $userId,
                $scope['scope_type'],
                $scope['scope_key'],
                $this->thoughtId,
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('RefreshWorkingMemoryIncremental failed permanently.', [
            'thought_id' => $this->thoughtId,
            'message' => $exception->getMessage(),
        ]);

        $thought = Thought::query()->find($this->thoughtId);
        if (! $thought instanceof Thought || $thought->user_id === null) {
            return;
        }

        WorkingMemory::query()
            ->where('user_id', $thought->user_id)
            ->whereNotNull('build_started_at')
            ->update(['build_started_at' => null]);
    }
}
