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
     *     rows?: list<array{
     *       title: string,
     *       href: string,
     *       badge: string|null,
     *       freshness: string|null,
     *       refreshed: string|null,
     *       aria_label: string,
     *       depth?: int,
     *       stream_href?: string
     *     }>,
     *     groups?: list<array{
     *       client_slug: string,
     *       client_title: string,
     *       rows: list<array{
     *         title: string,
     *         href: string,
     *         badge: string|null,
     *         freshness: string|null,
     *         refreshed: string|null,
     *         aria_label: string,
     *         depth: int,
     *         stream_href?: string
     *       }>
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
        $projectMemories = $this->dedupeProjectMemories($projectMemories, $projects);

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
            foreach ($this->buildProjectSections($userId, $projectMemories, $projects, $tagCanonicalBySlug) as $section) {
                $sections[] = $section;
            }
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
     * @param  Collection<int, WorkingMemory>  $projectMemories
     * @param  Collection<string, Project>  $projects
     * @param  array<string, string|null>  $tagCanonicalBySlug
     * @return list<array{key: string, title: string, rows?: list<array<string, mixed>>, groups?: list<array<string, mixed>>}>
     */
    private function buildProjectSections(
        int $userId,
        Collection $projectMemories,
        Collection $projects,
        array $tagCanonicalBySlug,
    ): array {
        /** @var array<string, array{client_slug: string, client_title: string, rows: list<array<string, mixed>>, latest_refreshed: Carbon}> $clientBuckets */
        $clientBuckets = [];
        /** @var list<array{row: array<string, mixed>, refreshed: Carbon}> $otherRows */
        $otherRows = [];

        foreach ($projectMemories as $memory) {
            $classification = $this->classifyProjectMemory($memory, $projects);
            $refreshed = $memory->last_refreshed_at ?? Carbon::create(1970, 1, 1);
            $row = $this->rowFor(
                $userId,
                $memory,
                $projects,
                $tagCanonicalBySlug,
                $classification['depth'],
                $classification['include_stream_link'] ? $classification['client_slug'] : null,
            );
            $row['_refreshed_ts'] = $refreshed->getTimestamp();

            if ($classification['client_slug'] !== null) {
                $clientSlug = $classification['client_slug'];
                if (! isset($clientBuckets[$clientSlug])) {
                    $clientBuckets[$clientSlug] = [
                        'client_slug' => $clientSlug,
                        'client_title' => $classification['client_title'],
                        'rows' => [],
                        'latest_refreshed' => $refreshed,
                    ];
                } elseif ($refreshed->gt($clientBuckets[$clientSlug]['latest_refreshed'])) {
                    $clientBuckets[$clientSlug]['latest_refreshed'] = $refreshed;
                }
                $clientBuckets[$clientSlug]['rows'][] = $row;
            } else {
                $otherRows[] = [
                    'row' => $row,
                    'refreshed' => $refreshed,
                ];
            }
        }

        $sections = [];

        if ($clientBuckets !== []) {
            $groups = $this->finalizeClientGroups($clientBuckets);
            $sections[] = [
                'key' => 'clients',
                'title' => 'Clients',
                'groups' => $groups,
            ];
        }

        if ($otherRows !== []) {
            usort($otherRows, fn (array $a, array $b): int => $b['refreshed'] <=> $a['refreshed']);
            $sections[] = [
                'key' => 'other_projects',
                'title' => 'Other projects',
                'rows' => array_map(function (array $entry): array {
                    unset($entry['row']['_refreshed_ts']);

                    return $entry['row'];
                }, $otherRows),
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string, array{client_slug: string, client_title: string, rows: list<array<string, mixed>>, latest_refreshed: Carbon}>  $clientBuckets
     * @return list<array{client_slug: string, client_title: string, rows: list<array<string, mixed>>}>
     */
    private function finalizeClientGroups(array $clientBuckets): array
    {
        $groups = [];
        foreach ($clientBuckets as $bucket) {
            $rows = $bucket['rows'];
            usort($rows, function (array $a, array $b): int {
                $depthCompare = ($a['depth'] ?? 0) <=> ($b['depth'] ?? 0);
                if ($depthCompare !== 0) {
                    return $depthCompare;
                }

                return ($b['_refreshed_ts'] ?? 0) <=> ($a['_refreshed_ts'] ?? 0);
            });
            $bucket['rows'] = array_values(array_map(function (array $row): array {
                unset($row['_refreshed_ts']);

                return $row;
            }, $rows));
            $groups[] = $bucket;
        }

        usort($groups, fn (array $a, array $b): int => $b['latest_refreshed'] <=> $a['latest_refreshed']);

        return array_values(array_map(function (array $group): array {
            unset($group['latest_refreshed']);

            return $group;
        }, $groups));
    }

    /**
     * @param  Collection<string, Project>  $projects
     * @return array{client_slug: string|null, client_title: string, depth: int, include_stream_link: bool}
     */
    private function classifyProjectMemory(WorkingMemory $memory, Collection $projects): array
    {
        $scopeKey = (string) $memory->scope_key;
        $project = $projects->get(Str::lower($scopeKey));

        if ($project !== null) {
            if ($project->isElixirrClientRoot()) {
                return [
                    'client_slug' => (string) $project->elixirr_client_slug,
                    'client_title' => $project->title,
                    'depth' => 0,
                    'include_stream_link' => true,
                ];
            }

            if ($this->isElixirrClientChild($project)) {
                return [
                    'client_slug' => (string) $project->elixirr_client_slug,
                    'client_title' => $this->clientTitleForSlug((string) $project->elixirr_client_slug, $projects),
                    'depth' => 1,
                    'include_stream_link' => false,
                ];
            }

            return [
                'client_slug' => null,
                'client_title' => '',
                'depth' => 0,
                'include_stream_link' => false,
            ];
        }

        if (str_contains($scopeKey, '/')) {
            $parts = explode('/', $scopeKey, 2);
            $clientSlug = Str::lower(trim($parts[0]));
            if ($clientSlug !== '') {
                return [
                    'client_slug' => $clientSlug,
                    'client_title' => $this->readableClientTitle($clientSlug),
                    'depth' => 1,
                    'include_stream_link' => false,
                ];
            }
        }

        return [
            'client_slug' => null,
            'client_title' => '',
            'depth' => 0,
            'include_stream_link' => false,
        ];
    }

    private function isElixirrClientChild(Project $project): bool
    {
        return $project->elixirr_client_slug !== null
            && ! $project->isElixirrClientRoot();
    }

    /**
     * @param  Collection<string, Project>  $projects
     */
    private function clientTitleForSlug(string $clientSlug, Collection $projects): string
    {
        $root = $projects->first(
            fn (Project $project): bool => $project->isElixirrClientRoot()
                && Str::lower((string) $project->elixirr_client_slug) === Str::lower($clientSlug),
        );

        return $root?->title ?? $this->readableClientTitle($clientSlug);
    }

    private function readableClientTitle(string $clientSlug): string
    {
        return Str::of($clientSlug)
            ->replace(['-', '_'], ' ')
            ->squish()
            ->title()
            ->toString();
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
     * Collapse slug + UUID (and other aliases) that resolve to the same project into one row.
     *
     * @param  Collection<int, WorkingMemory>  $projectMemories
     * @param  Collection<string, Project>  $projects
     * @return Collection<int, WorkingMemory>
     */
    private function dedupeProjectMemories(Collection $projectMemories, Collection $projects): Collection
    {
        if ($projectMemories->count() < 2) {
            return $projectMemories;
        }

        /** @var array<string, WorkingMemory> $winners */
        $winners = [];

        foreach ($projectMemories as $memory) {
            $key = $this->projectMemoryDedupeKey($memory, $projects);
            $existing = $winners[$key] ?? null;

            if ($existing === null || $this->shouldPreferProjectMemory($memory, $existing)) {
                $winners[$key] = $memory;
            }
        }

        return $this->sortByRefresh(collect(array_values($winners)));
    }

    /**
     * @param  Collection<string, Project>  $projects
     */
    private function projectMemoryDedupeKey(WorkingMemory $memory, Collection $projects): string
    {
        $scopeKey = Str::lower((string) $memory->scope_key);
        $project = $projects->get($scopeKey);

        if ($project !== null) {
            return 'project:'.Str::lower((string) $project->getKey());
        }

        return 'orphan:'.$scopeKey;
    }

    private function shouldPreferProjectMemory(WorkingMemory $candidate, WorkingMemory $incumbent): bool
    {
        $candidateRefreshed = $candidate->last_refreshed_at ?? Carbon::create(1970, 1, 1);
        $incumbentRefreshed = $incumbent->last_refreshed_at ?? Carbon::create(1970, 1, 1);

        if ($candidateRefreshed->gt($incumbentRefreshed)) {
            return true;
        }

        if ($candidateRefreshed->lt($incumbentRefreshed)) {
            return false;
        }

        $candidateKey = (string) $candidate->scope_key;
        $incumbentKey = (string) $incumbent->scope_key;

        if (Str::isUuid($candidateKey) && ! Str::isUuid($incumbentKey)) {
            return true;
        }

        if (! Str::isUuid($candidateKey) && Str::isUuid($incumbentKey)) {
            return false;
        }

        return false;
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

        $elixirrProjects = Project::query()
            ->where('user_id', $userId)
            ->whereNotNull('elixirr_client_slug')
            ->get();

        return $this->projectLookupMap(
            $projects
                ->merge($elixirrProjects)
                ->unique(fn (Project $project): string => (string) $project->getKey()),
        );
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
     * @return array{title: string, href: string, badge: string|null, freshness: string|null, refreshed: string|null, aria_label: string, depth: int, stream_href?: string}
     */
    private function rowFor(
        int $userId,
        WorkingMemory $memory,
        Collection $projects,
        array $tagCanonicalBySlug = [],
        int $depth = 0,
        ?string $streamClientSlug = null,
    ): array {
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

        $row = [
            'title' => $title,
            'href' => $this->hrefFor($memory, $project, $tagCanonicalBySlug),
            'badge' => $badge,
            'freshness' => $memory->freshness_state,
            'refreshed' => $memory->last_refreshed_at?->diffForHumans(),
            'aria_label' => $ariaLabel,
            'depth' => $depth,
        ];

        if ($streamClientSlug !== null && $streamClientSlug !== '') {
            $row['stream_href'] = route('idea.stream', [
                'tag' => TagSlug::from('client:'.$streamClientSlug),
            ]);
        }

        return $row;
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
            'project' => $project?->title ?? (
                Str::isUuid($scopeKey) ? 'Unavailable project' : $this->readableProjectTitle($scopeKey)
            ),
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
                : (Str::isUuid($scopeKey)
                    ? route('projects.index')
                    : route('memory.project-scope.show', ['scopeKey' => $scopeKey])),
            'tag' => route('memory.tag.show', [
                'tag' => TagSlug::from(($tagCanonicalBySlug[$scopeKey] ?? null) ?? $scopeKey),
            ]),
            default => route('memory.show'),
        };
    }

    private function readableProjectTitle(string $scopeKey): string
    {
        if (str_contains($scopeKey, '/')) {
            $parts = explode('/', $scopeKey, 2);

            return Str::of($parts[0])->replace(['-', '_'], ' ')->squish()->title()->toString()
                .' / '
                .Str::of($parts[1])->replace(['-', '_'], ' ')->squish()->title()->toString();
        }

        return Str::of($scopeKey)
            ->replace(['-', '_'], ' ')
            ->squish()
            ->title()
            ->toString();
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
