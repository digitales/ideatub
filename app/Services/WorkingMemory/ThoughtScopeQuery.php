<?php

namespace App\Services\WorkingMemory;

use App\Models\Project;
use App\Models\Thought;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ThoughtScopeQuery
{
    public function __construct(
        private readonly MemoryInsightsService $memoryInsightsService,
    ) {}

    /**
     * @return Collection<int, Thought>
     */
    public function forScope(
        int $userId,
        string $scopeType,
        string $scopeKey,
        ?Carbon $since = null,
        int $limit = 200,
    ): Collection {
        $normalizedScopeType = Str::of($scopeType)->trim()->lower()->toString();
        $normalizedScopeKey = Str::of($scopeKey)->trim()->lower()->toString();

        if ($normalizedScopeType === 'insights') {
            return $this->forInsightsScope($userId, $since, $limit);
        }

        $query = Thought::query()
            ->where('user_id', $userId)
            ->visibleInStream()
            ->with('projects:id')
            ->orderByDesc('created_at');

        if ($since !== null) {
            $query->where('created_at', '>', $since);
        }

        $this->applyScopeFilter($query, $userId, $normalizedScopeType, $normalizedScopeKey);

        return $query->limit(max(1, $limit))->get();
    }

    public function applyScopeFilter(
        Builder $query,
        int $userId,
        string $scopeType,
        string $scopeKey,
    ): void {
        if ($scopeType === 'global') {
            return;
        }

        if ($scopeType === 'tag') {
            $query->whereJsonContains('metadata->tags', $scopeKey);

            return;
        }

        if ($scopeType === 'project') {
            $this->applyProjectScopeFilter($query, $userId, $scopeKey);

            return;
        }
    }

    private function applyProjectScopeFilter(Builder $query, int $userId, string $scopeKey): void
    {
        if (Str::isUuid($scopeKey)) {
            $project = Project::query()
                ->where('user_id', $userId)
                ->find($scopeKey);

            if ($project !== null) {
                if ($project->isElixirrClientRoot()) {
                    $childIds = $project->children()->pluck('id');
                    $clientSlug = Str::of((string) $project->elixirr_client_slug)->trim()->lower()->toString();

                    $query->where(function (Builder $scoped) use ($project, $childIds, $clientSlug): void {
                        $scoped->whereHas('projects', fn (Builder $p) => $p->where('projects.id', $project->id));

                        foreach ($childIds as $childId) {
                            $scoped->orWhereHas('projects', fn (Builder $p) => $p->where('projects.id', $childId));
                        }

                        if ($clientSlug !== '') {
                            $scoped->orWhere('source_metadata->project', $clientSlug);
                            $scoped->orWhereJsonContains('metadata->tags', 'client:'.$clientSlug);
                        }
                    });

                    return;
                }

                if ($project->parent_project_id !== null) {
                    $compositeKey = Str::of((string) $project->elixirr_client_slug)
                        ->trim()
                        ->lower()
                        ->append('/')
                        ->append(Str::of((string) $project->elixirr_project_slug)->trim()->lower())
                        ->toString();

                    $query->where(function (Builder $scoped) use ($project, $compositeKey): void {
                        $scoped->whereHas('projects', fn (Builder $p) => $p->where('projects.id', $project->id));

                        if ($compositeKey !== '/') {
                            $scoped->orWhere('source_metadata->project', $compositeKey);
                        }
                    });

                    return;
                }
            }
        }

        $query->where(function (Builder $scoped) use ($scopeKey): void {
            $scoped->where('source_metadata->project', $scopeKey);

            if (Str::isUuid($scopeKey)) {
                $scoped->orWhereHas('projects', fn (Builder $p) => $p->where('projects.id', $scopeKey));
            }
        });
    }

    /**
     * @return Collection<int, Thought>
     */
    private function forInsightsScope(int $userId, ?Carbon $since, int $limit): Collection
    {
        $pool = $this->memoryInsightsService->recentThoughtPool($userId);

        return $pool
            ->filter(fn (Thought $thought): bool => $this->memoryInsightsService->isResearchThought($thought))
            ->when(
                $since !== null,
                fn (Collection $thoughts): Collection => $thoughts->filter(
                    fn (Thought $thought): bool => $thought->created_at !== null && $thought->created_at->gt($since)
                )
            )
            ->take(max(1, $limit))
            ->values();
    }
}
