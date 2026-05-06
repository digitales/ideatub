<?php

namespace App\Jobs;

use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ConsolidateWorkingMemory implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $userId,
        private readonly string $scopeType,
        private readonly string $scopeKey
    ) {}

    public function handle(WorkingMemoryBuilderService $builderService): void
    {
        $builderService->buildConsolidated($this->userId, $this->scopeType, $this->scopeKey);
    }
}
