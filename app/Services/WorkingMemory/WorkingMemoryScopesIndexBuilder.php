<?php

namespace App\Services\WorkingMemory;

use App\Models\Project;
use App\Models\WorkingMemory;
use App\Services\Tags\UserCanonicalTagResolver;
use App\Support\TagSlug;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class WorkingMemoryScopesIndexBuilder
{
    public function __construct(
        private readonly UserCanonicalTagResolver $canonicalTagResolver,
    ) {}

    /**
     * @return array{
     *   sections: list<array{
     *     key: string,
     *     title: string,
     *     rows: list<array{
     *       title: string,
     *       href: string,
     *       badge: string|null,
     *       freshness: string|null,
     *       refreshed: string|null,
     *       aria_label: string
     *     }>
     *   }>
     * }
     */
    public function build(int $userId): array
    {
        $memories = WorkingMemory::query()
            ->where('user_id', $userId)
            ->with('latestVersion')
            ->get();

        if ($memories->isEmpty()) {
            return ['sections' => []];
        }

        $globalMemories = $this->sortByRefresh($memories->where('scope_type', 'global'))->take(1);
        $insightsMemories = $this->sortByRefresh($memories->where('scope_type', 'insights'))->take(1);
        $projectMemories = $this->sortByRefresh($memories->where('scope_type', 'project'));
        $tagMemories = $this->sortByRefresh($memories->where('scope_type', 'tag'));

        $projects = $this->projectsFor($userId, $projectMemories);

        $tagCanonicalBySlug = [];
        if ($tagMemories->isNotEmpty()) {
            $lookupSlugs = $tagMemories
                ->pluck('scope_key')
                ->map(fn ($k): string => TagSlug::from((string) $k))
                ->unique()
                ->values()
                ->all();
            $resolvedBySlug = $this->canonicalTagResolver->resolveMany($userId, $lookupSlugs);
            foreach ($tagMemories->pluck('scope_key')->unique() as $storedKey) {
                $storedKey = (string) $storedKey;
                $slug = TagSlug::from($storedKey);
                $tagCanonicalBySlug[$storedKey] = $resolvedBySlug[$slug] ?? null;
            }
        }

        $sections = [];

        if ($globalMemories->isNotEmpty()) {
            $sections[] = [
                'key' => 'global',
                'title' => 'Global',
                'rows' => $globalMemories->map(fn (WorkingMemory $memory): array => $this->rowFor($userId, $memory, $projects, $tagCanonicalBySlug))->values()->all(),
            ];
        }

        if ($insightsMemories->isNotEmpty()) {
            $sections[] = [
                'key' => 'insights',
                'title' => 'Insights',
                'rows' => $insightsMemories->map(fn (WorkingMemory $memory): array => $this->rowFor($userId, $memory, $projects, $tagCanonicalBySlug))->values()->all(),
            ];
        }

        if ($projectMemories->isNotEmpty()) {
            $sections[] = [
                'key' => 'projects',
                'title' => 'Projects',
                'rows' => $projectMemories->map(fn (WorkingMemory $memory): array => $this->rowFor($userId, $memory, $projects, $tagCanonicalBySlug))->values()->all(),
            ];
        }

        if ($tagMemories->isNotEmpty()) {
            $sections[] = [
                'key' => 'tags',
                'title' => 'Tags',
                'rows' => $tagMemories->map(fn (WorkingMemory $memory): array => $this->rowFor($userId, $memory, $projects, $tagCanonicalBySlug))->values()->all(),
            ];
        }

        return ['sections' => $sections];
    }

    /**
     * @param  Collection<int, WorkingMemory>  $memories
     * @return Collection<int, WorkingMemory>
     */
    private function sortByRefresh(Collection $memories): Collection
    {
        return $memories
            ->sortByDesc(fn (WorkingMemory $memory): Carbon => $memory->last_refreshed_at ?? Carbon::create(1970, 1, 1))
            ->values();
    }

    /**
     * @param  Collection<int, WorkingMemory>  $projectMemories
     * @return Collection<string, Project>
     */
    private function projectsFor(int $userId, Collection $projectMemories): Collection
    {
        $scopeKeys = $projectMemories
            ->pluck('scope_key')
            ->map(fn ($key): string => Str::lower((string) $key))
            ->filter()
            ->unique()
            ->values();

        if ($scopeKeys->isEmpty()) {
            return collect();
        }

        $uuidKeys = $scopeKeys->filter(fn (string $key): bool => Str::isUuid($key))->values();
        $slugKeys = $scopeKeys->reject(fn (string $key): bool => Str::isUuid($key))->values();

        $projects = collect();

        if ($uuidKeys->isNotEmpty()) {
            $projects = $projects->merge(
                Project::query()
                    ->where('user_id', $userId)
                    ->whereIn('id', $uuidKeys)
                    ->get()
            );
        }

        if ($slugKeys->isNotEmpty()) {
            $projects = $projects->merge(
                Project::query()
                    ->where('user_id', $userId)
                    ->get()
                    ->filter(function (Project $project) use ($slugKeys): bool {
                        $slug = Str::slug($project->title);

                        return $slug !== '' && $slugKeys->contains(Str::lower($slug));
                    })
            );
        }

        return $this->projectLookupMap($projects->unique(fn (Project $project): string => (string) $project->getKey()));
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return Collection<string, Project>
     */
    private function projectLookupMap(Collection $projects): Collection
    {
        $map = collect();

        foreach ($projects as $project) {
            $map[Str::lower((string) $project->getKey())] = $project;

            $slug = Str::slug($project->title);
            if ($slug !== '') {
                $map[Str::lower($slug)] = $project;
            }
        }

        return $map;
    }

    /**
     * @param  Collection<string, Project>  $projects
     * @param  array<string, string|null>  $tagCanonicalBySlug
     * @return array{title: string, href: string, badge: string|null, freshness: string|null, refreshed: string|null, aria_label: string}
     */
    private function rowFor(int $userId, WorkingMemory $memory, Collection $projects, array $tagCanonicalBySlug = []): array
    {
        $scopeKey = (string) $memory->scope_key;
        $project = null;

        if ($memory->scope_type === 'project') {
            $project = $projects->get(Str::lower($scopeKey));
        }

        $title = $this->titleFor($userId, $memory, $project, $tagCanonicalBySlug);
        $badge = WorkingMemoryScopeRowBadge::label($memory);
        $ariaLabel = "{$title} working memory";

        if ($badge !== null) {
            $ariaLabel .= " ({$badge})";
        }

        return [
            'title' => $title,
            'href' => $this->hrefFor($memory, $project, $tagCanonicalBySlug),
            'badge' => $badge,
            'freshness' => $memory->freshness_state,
            'refreshed' => $memory->last_refreshed_at?->diffForHumans(),
            'aria_label' => $ariaLabel,
        ];
    }

    /**
     * @param  array<string, string|null>  $tagCanonicalBySlug
     */
    private function titleFor(int $userId, WorkingMemory $memory, ?Project $project, array $tagCanonicalBySlug): string
    {
        $scopeKey = (string) $memory->scope_key;

        return match ($memory->scope_type) {
            'global' => 'Global',
            'insights' => 'Insights',
            'project' => $project?->title ?? 'Unavailable project',
            'tag' => ($tagCanonicalBySlug[$scopeKey] ?? null) ?? $this->readableTagTitle($scopeKey),
            default => $scopeKey,
        };
    }

    /**
     * @param  array<string, string|null>  $tagCanonicalBySlug
     */
    private function hrefFor(WorkingMemory $memory, ?Project $project, array $tagCanonicalBySlug = []): string
    {
        $scopeKey = (string) $memory->scope_key;

        return match ($memory->scope_type) {
            'global' => route('memory.show'),
            'insights' => route('memory.insights'),
            'project' => $project !== null
                ? route('projects.memory.show', $project)
                : route('projects.index'),
            'tag' => route('memory.tag.show', [
                'tag' => TagSlug::from(($tagCanonicalBySlug[$scopeKey] ?? null) ?? $scopeKey),
            ]),
            default => route('memory.show'),
        };
    }

    private function readableTagTitle(string $scopeKey): string
    {
        return Str::of($scopeKey)
            ->replace(['-', '_'], ' ')
            ->squish()
            ->title()
            ->toString();
    }
}
