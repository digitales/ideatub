<?php

namespace App\Services\Attention;

use App\DataTransferObjects\AttentionItemData;
use App\Models\Project;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryScopeRowBadge;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class MemoryHealthAttentionQuery
{
    public function __construct(
        private readonly AttentionScopeResolver $scopeResolver,
    ) {}

    /**
     * @return list<AttentionItemData>
     */
    public function forUser(int $userId): array
    {
        $memories = WorkingMemory::query()
            ->where('user_id', $userId)
            ->with('latestVersion')
            ->get();

        if ($memories->isEmpty()) {
            return [];
        }

        $projectMemories = $memories->where('scope_type', 'project');
        $projects = $this->scopeResolver->projectsFor($userId, $projectMemories);

        $staleDays = max(1, (int) config('pulse.memory_stale_days', 14));
        $staleCutoff = now()->subDays($staleDays);
        $externalProtectDays = max(0, (int) config('working_memory.external_protect_days', 14));

        $rows = [];

        foreach ($memories as $memory) {
            $signals = $this->signalsFor($memory, $projects, $staleCutoff, $externalProtectDays, $userId);
            foreach ($signals as $signal) {
                $rows[] = $signal;
            }
        }

        usort($rows, function (AttentionItemData $a, AttentionItemData $b): int {
            $severity = $this->severityRank($a->severity) <=> $this->severityRank($b->severity);
            if ($severity !== 0) {
                return $severity;
            }

            return strcmp((string) ($a->meta['refreshed_at'] ?? ''), (string) ($b->meta['refreshed_at'] ?? ''));
        });

        $limit = max(1, (int) config('pulse.max_memory_health', 10));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param  Collection<string, Project>  $projects
     * @return list<AttentionItemData>
     */
    private function signalsFor(
        WorkingMemory $memory,
        Collection $projects,
        \Illuminate\Support\Carbon $staleCutoff,
        int $externalProtectDays,
        int $userId,
    ): array {
        $scope = $this->scopeResolver->resolve($memory, $projects);
        $badge = WorkingMemoryScopeRowBadge::label($memory);
        $items = [];

        if ($badge === 'Updating') {
            $items[] = $this->item($scope, $memory, 'high', 'Build in progress', 'Working memory is updating');
        }

        if ($badge === 'Fallback') {
            $items[] = $this->item($scope, $memory, 'high', 'Fallback authoring', 'Compose failed — legacy summary is shown');
        }

        if (in_array($memory->freshness_state, ['stale', 'degraded'], true)) {
            $items[] = $this->item($scope, $memory, 'medium', 'Freshness '.$memory->freshness_state, 'Memory may be out of date');
        }

        if ($memory->last_refreshed_at !== null && $memory->last_refreshed_at->lt($staleCutoff)) {
            $items[] = $this->item(
                $scope,
                $memory,
                'low',
                'Not refreshed recently',
                'Last refresh '.$memory->last_refreshed_at->diffForHumans(),
            );
        }

        if ($memory->scope_type === 'project' && $this->shouldFlagMissingExternal($memory, $projects, $externalProtectDays, $userId)) {
            $items[] = $this->item(
                $scope,
                $memory,
                'medium',
                'No recent agent sync',
                'Run elixirr-sync or upsert_working_memory for curated memory',
            );
        }

        return $items;
    }

    /**
     * @param  array{title: string, href: string, project_id: string|null, project_title: string|null}  $scope
     */
    private function item(
        array $scope,
        WorkingMemory $memory,
        string $severity,
        string $subtitle,
        string $detail,
    ): AttentionItemData {
        return new AttentionItemData(
            kind: 'memory_health',
            severity: $severity,
            title: $scope['title'],
            subtitle: trim($subtitle.' — '.$detail),
            href: $scope['href'],
            meta: [
                'scope_type' => $memory->scope_type,
                'scope_key' => $memory->scope_key,
                'project_title' => $scope['project_title'],
                'refreshed_at' => $memory->last_refreshed_at?->toIso8601String(),
            ],
            sourceRef: [
                'type' => 'working_memory',
                'id' => (string) $memory->id,
            ],
        );
    }

    /**
     * @param  Collection<string, Project>  $projects
     */
    private function shouldFlagMissingExternal(
        WorkingMemory $memory,
        Collection $projects,
        int $externalProtectDays,
        int $userId,
    ): bool {
        $project = $projects->get(Str::lower((string) $memory->scope_key));
        if ($project === null) {
            return false;
        }

        if ($project->elixirr_client_slug === null) {
            return false;
        }

        $hasRecentExternal = WorkingMemoryVersion::query()
            ->whereHas('workingMemory', function ($query) use ($userId, $memory): void {
                $query->where('user_id', $userId)
                    ->where('scope_type', $memory->scope_type)
                    ->where('scope_key', $memory->scope_key);
            })
            ->where('build_type', 'external')
            ->where('authoring_status', 'external')
            ->where('created_at', '>=', now()->subDays(max(1, $externalProtectDays)))
            ->exists();

        return ! $hasRecentExternal;
    }

    private function severityRank(?string $severity): int
    {
        return match ($severity) {
            'high' => 0,
            'medium' => 1,
            'low' => 2,
            default => 3,
        };
    }
}
