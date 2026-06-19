<?php

namespace App\Jobs;

use App\Models\WorkingMemory;
use App\Services\WorkingMemory\UncompactedThoughtResolver;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshWorkingMemoryIncrementalScope implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout;

    public function __construct(
        private readonly int $userId,
        private readonly string $scopeType,
        private readonly string $scopeKey,
        private readonly ?string $thoughtId = null,
    ) {
        $this->timeout = max(
            60,
            (int) config('working_memory.incremental_scope_job_timeout_seconds', 600),
        );
    }

    public function handle(
        WorkingMemoryBuilderService $builderService,
        UncompactedThoughtResolver $uncompactedThoughtResolver,
    ): void {
        if (! $uncompactedThoughtResolver->shouldRunIncrementalBuild(
            $this->userId,
            $this->scopeType,
            $this->scopeKey,
        )) {
            Log::info('RefreshWorkingMemoryIncrementalScope skipped: no compaction delta.', [
                'user_id' => $this->userId,
                'scope_type' => $this->scopeType,
                'scope_key' => $this->scopeKey,
                'thought_id' => $this->thoughtId,
            ]);

            WorkingMemory::query()
                ->where('user_id', $this->userId)
                ->where('scope_type', $this->scopeType)
                ->where('scope_key', $this->scopeKey)
                ->whereNotNull('build_started_at')
                ->update(['build_started_at' => null]);

            return;
        }

        $builderService->buildIncremental($this->userId, $this->scopeType, $this->scopeKey);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('RefreshWorkingMemoryIncrementalScope failed permanently.', [
            'user_id' => $this->userId,
            'scope_type' => $this->scopeType,
            'scope_key' => $this->scopeKey,
            'thought_id' => $this->thoughtId,
            'message' => $exception->getMessage(),
        ]);

        WorkingMemory::query()
            ->where('user_id', $this->userId)
            ->where('scope_type', $this->scopeType)
            ->where('scope_key', $this->scopeKey)
            ->whereNotNull('build_started_at')
            ->update(['build_started_at' => null]);
    }
}
