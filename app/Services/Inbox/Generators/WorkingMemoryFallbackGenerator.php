<?php

namespace App\Services\Inbox\Generators;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Services\Attention\AttentionScopeResolver;
use App\Services\Inbox\Contracts\InboxGenerator;
use App\Services\WorkingMemory\WorkingMemoryScopeRowBadge;

class WorkingMemoryFallbackGenerator implements InboxGenerator
{
    public function __construct(
        private readonly AttentionScopeResolver $scopeResolver,
    ) {}

    public function generate(User $user): array
    {
        if (! config('features.attention_pulse')) {
            return [];
        }

        $memories = WorkingMemory::query()
            ->where('user_id', $user->id)
            ->with('latestVersion')
            ->get()
            ->filter(fn (WorkingMemory $memory): bool => WorkingMemoryScopeRowBadge::label($memory) === 'Fallback');

        if ($memories->isEmpty()) {
            return [];
        }

        $projects = $this->scopeResolver->projectsFor($user->id, $memories->where('scope_type', 'project'));

        return $memories->map(function (WorkingMemory $memory) use ($projects): array {
            $scope = $this->scopeResolver->resolve($memory, $projects);

            return [
                'generator_type' => 'wm_fallback',
                'title' => 'Working memory needs consolidate',
                'body' => "{$scope['title']} is in fallback authoring. Run consolidate or sync curated memory.",
                'dedupe_key' => 'wm_fallback:'.$memory->id,
                'generated_at' => now(),
                'source_data' => [
                    'working_memory_id' => $memory->id,
                    'scope_type' => $memory->scope_type,
                    'scope_key' => $memory->scope_key,
                    'href' => $scope['href'],
                ],
            ];
        })->values()->all();
    }
}
