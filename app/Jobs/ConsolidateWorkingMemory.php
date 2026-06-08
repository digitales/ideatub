<?php

namespace App\Jobs;

use App\Models\WorkingMemory;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use App\Services\WorkingMemory\WorkingMemoryExternalGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConsolidateWorkingMemory implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly int $userId,
        private readonly string $scopeType,
        private readonly string $scopeKey,
        public bool $force = false,
        public bool $freshStart = false,
    ) {}

    public function handle(WorkingMemoryBuilderService $builderService): void
    {
        if (app(WorkingMemoryExternalGuard::class)->shouldSkipConsolidatedBuild(
            $this->userId,
            $this->scopeType,
            $this->scopeKey,
            $this->force,
        )) {
            Log::info('ConsolidateWorkingMemory skipped: fresh external memory protected.', [
                'user_id' => $this->userId,
                'scope_type' => $this->scopeType,
                'scope_key' => $this->scopeKey,
            ]);

            return;
        }

        $builderService->buildConsolidated(
            $this->userId,
            $this->scopeType,
            $this->scopeKey,
            $this->freshStart,
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('ConsolidateWorkingMemory failed permanently.', [
            'user_id' => $this->userId,
            'scope_type' => $this->scopeType,
            'scope_key' => $this->scopeKey,
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
