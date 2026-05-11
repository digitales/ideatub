<?php

namespace App\Jobs;

use App\Models\WorkingMemory;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
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
        private readonly string $scopeKey
    ) {}

    public function handle(WorkingMemoryBuilderService $builderService): void
    {
        $builderService->buildConsolidated($this->userId, $this->scopeType, $this->scopeKey);
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
