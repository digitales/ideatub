<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use App\Services\WorkingMemory\WorkingMemoryScopeResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshWorkingMemoryIncremental implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $thoughtId
    ) {}

    public function handle(
        WorkingMemoryScopeResolver $scopeResolver,
        WorkingMemoryBuilderService $builderService
    ): void {
        $thought = Thought::query()->with('projects:id')->find($this->thoughtId);
        if (! $thought instanceof Thought || $thought->user_id === null) {
            return;
        }

        foreach ($scopeResolver->forThought($thought) as $scope) {
            $builderService->buildIncremental(
                (int) $thought->user_id,
                $scope['scope_type'],
                $scope['scope_key']
            );
        }
    }
}
