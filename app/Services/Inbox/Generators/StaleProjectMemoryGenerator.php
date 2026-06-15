<?php

namespace App\Services\Inbox\Generators;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Services\Attention\AttentionScopeResolver;
use App\Services\Inbox\Contracts\InboxGenerator;

class StaleProjectMemoryGenerator implements InboxGenerator
{
    public function __construct(
        private readonly AttentionScopeResolver $scopeResolver,
    ) {}

    public function generate(User $user): array
    {
        if (! config('features.attention_pulse')) {
            return [];
        }

        $staleDays = max(1, (int) config('pulse.memory_stale_days', 14));
        $staleCutoff = now()->subDays($staleDays);

        $memories = WorkingMemory::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'project')
            ->get()
            ->filter(function (WorkingMemory $memory) use ($staleCutoff): bool {
                if (in_array($memory->freshness_state, ['stale', 'degraded'], true)) {
                    return true;
                }

                return $memory->last_refreshed_at !== null
                    && $memory->last_refreshed_at->lt($staleCutoff);
            });

        if ($memories->isEmpty()) {
            return [];
        }

        $projects = $this->scopeResolver->projectsFor($user->id, $memories);

        return $memories->map(function (WorkingMemory $memory) use ($projects): array {
            $scope = $this->scopeResolver->resolve($memory, $projects);
            $refreshed = $memory->last_refreshed_at?->diffForHumans() ?? 'never';

            return [
                'generator_type' => 'stale_project_memory',
                'title' => 'Project memory may be stale',
                'body' => "{$scope['title']} was last refreshed {$refreshed}. Review or sync curated memory.",
                'dedupe_key' => 'stale_project_memory:'.$memory->id,
                'generated_at' => now(),
                'source_data' => [
                    'working_memory_id' => $memory->id,
                    'scope_type' => $memory->scope_type,
                    'scope_key' => $memory->scope_key,
                    'freshness_state' => $memory->freshness_state,
                    'href' => $scope['href'],
                ],
            ];
        })->values()->all();
    }
}
