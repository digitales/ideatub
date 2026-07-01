<?php

namespace App\Services\Graph;

use App\Enums\ThoughtGraphMode;
use App\Enums\ThoughtLinkType;
use App\Models\Project;
use App\Models\Thought;
use App\Models\ThoughtLink;
use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use App\Support\TagSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ThoughtGraphService
{
    public function __construct(
        private readonly DemoMode $demoMode,
        private readonly DemoObfuscator $demoObfuscator,
    ) {}

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function build(ThoughtGraphQuery $query): array
    {
        $payload = match ($query->mode) {
            ThoughtGraphMode::Local => $this->buildLocal($query),
            ThoughtGraphMode::Project => $this->buildProject($query),
            ThoughtGraphMode::Tag => $this->buildTag($query),
            ThoughtGraphMode::Semantic => $this->buildSemantic($query),
            ThoughtGraphMode::Vault => $this->buildVault($query),
        };

        if ($this->demoMode->enabled()) {
            $payload['nodes'] = array_map(function (array $node) {
                if (($node['group'] ?? '') === 'hub') {
                    return $node;
                }
                $node['label'] = $this->demoObfuscator->obfuscate((string) $node['label'], 'thought_content');

                return $node;
            }, $payload['nodes']);
        }

        return $payload;
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function buildLocal(ThoughtGraphQuery $query): array
    {
        $focal = Thought::query()
            ->where('user_id', $query->userId)
            ->findOrFail($query->focalThoughtId);

        $thoughts = collect([$focal]);
        $linkedIds = $this->collectThoughtLinkNeighborhood($focal->id, $query->userId, $query->depth);

        if ($linkedIds->isNotEmpty()) {
            $thoughts = $thoughts->merge(
                Thought::query()->where('user_id', $query->userId)->whereIn('id', $linkedIds)->get()
            );
        }

        $thoughts = $this->appendParentChildNodes($thoughts, $focal, $query);
        $thoughts = $this->filterChunksForFocal($thoughts, $focal, $query->includeChunks)->unique('id')->values();

        $edges = $this->edgesForThoughtSet($thoughts, $query, $focal);

        $nodes = $thoughts->map(function (Thought $thought) use ($focal) {
            $group = $thought->id === $focal->id ? 'focal' : ($thought->parent_id !== null ? 'chunk' : 'member');

            return $this->nodePayload($thought, $group);
        })->values()->all();

        return $this->assemble($nodes, $edges, [
            'mode' => 'local',
            'focal_id' => $focal->id,
            'filters' => [
                'depth' => $query->depth,
                'include_parent_child' => $query->includeParentChild,
                'include_chunks' => $query->includeChunks,
                'include_semantic' => $query->includeSemantic,
            ],
        ]);
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function buildProject(ThoughtGraphQuery $query): array
    {
        $project = Project::query()
            ->where('user_id', $query->userId)
            ->findOrFail($query->projectId);

        $memberIds = $project->thoughts()->pluck('thoughts.id')->all();
        $thoughts = $project->thoughts()->orderByPivot('sort_order')->get();
        $memberIdSet = array_flip($memberIds);

        if ($query->includeParentChild) {
            $thoughts = $this->appendHierarchyForMembers($thoughts, $query->userId);
        }

        if ($query->includeNeighbors && $memberIds !== []) {
            $neighborThoughts = $this->neighborThoughts($memberIds, $query->userId, 50);
            $thoughts = $thoughts->merge($neighborThoughts)->unique('id')->values();
        }

        if (! $query->includeChunks) {
            $thoughts = $thoughts->filter(fn (Thought $t) => $t->parent_id === null || isset($memberIdSet[$t->id]))->values();
        }

        $edges = $this->edgesForThoughtSet($thoughts, $query);

        $nodes = $thoughts->map(function (Thought $thought) use ($memberIdSet) {
            $group = isset($memberIdSet[$thought->id]) ? 'member' : 'neighbor';
            if ($thought->parent_id !== null && $group === 'member') {
                $group = 'chunk';
            }

            return $this->nodePayload($thought, $group);
        })->values()->all();

        return $this->assemble($nodes, $edges, [
            'mode' => 'project',
            'focal_id' => null,
            'filters' => [
                'project_id' => $project->id,
                'include_neighbors' => $query->includeNeighbors,
                'include_parent_child' => $query->includeParentChild,
                'include_chunks' => $query->includeChunks,
                'include_semantic' => $query->includeSemantic,
                'link_types' => $query->linkTypes,
            ],
        ]);
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function buildTag(ThoughtGraphQuery $query): array
    {
        $tag = $query->tag ?? '';
        if ($tag === '') {
            return $this->assemble([], [], [
                'mode' => 'tag',
                'focal_id' => null,
                'filters' => [],
                'warnings' => ['tag_required'],
            ]);
        }

        $thoughts = $this->taggedThoughtsQuery($query->userId, $tag, $query->since)
            ->orderByDesc('updated_at')
            ->limit($query->limit)
            ->get();

        $slug = TagSlug::from($tag);
        $hubId = 'tag:'.$slug;
        $nodes = $thoughts->map(fn (Thought $t) => $this->nodePayload($t, 'member'))->values()->all();
        $nodes[] = [
            'id' => $hubId,
            'label' => '#'.$tag,
            'group' => 'hub',
            'source_type' => null,
            'tags' => [$tag],
            'url' => null,
        ];

        $edges = [];
        foreach ($thoughts as $thought) {
            $edges[] = [
                'id' => 'tag-hub:'.$hubId.':'.$thought->id,
                'from' => $hubId,
                'to' => $thought->id,
                'edge_type' => 'shared_tag',
                'label' => '#'.$tag,
                'directed' => false,
                'dashed' => true,
            ];
        }

        if ($query->includeSemantic && config('features.memory_graph_semantic')) {
            $edges = array_merge($edges, $this->semanticEdgesAmongMembers($thoughts, $query));
        }

        return $this->assemble($nodes, $edges, [
            'mode' => 'tag',
            'focal_id' => null,
            'filters' => ['tag' => $tag, 'include_semantic' => $query->includeSemantic],
        ]);
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function buildSemantic(ThoughtGraphQuery $query): array
    {
        $focal = Thought::query()
            ->where('user_id', $query->userId)
            ->findOrFail($query->focalThoughtId);

        if ($focal->embedding === null) {
            return $this->assemble(
                [$this->nodePayload($focal, 'focal')],
                [],
                [
                    'mode' => 'semantic',
                    'focal_id' => $focal->id,
                    'filters' => [],
                    'error' => 'no_embedding',
                ],
            );
        }

        $neighbors = Thought::query()
            ->where('user_id', $query->userId)
            ->where('id', '!=', $focal->id)
            ->visibleInStream()
            ->whereNotNull('embedding')
            ->nearestWithin($focal->embedding, $query->maxDistance)
            ->limit($query->semanticK)
            ->get();

        $thoughts = collect([$focal])->merge($neighbors)->unique('id')->values();
        $edges = $this->semanticEdgesFromFocal($focal, $neighbors);

        if ($query->includeLinksAmongSemantic) {
            $edges = array_merge($edges, $this->curatedEdges($thoughts->pluck('id')->all(), $query->userId));
        }

        $nodes = $thoughts->map(fn (Thought $t) => $this->nodePayload($t, $t->id === $focal->id ? 'focal' : 'member'))->all();

        return $this->assemble($nodes, $edges, [
            'mode' => 'semantic',
            'focal_id' => $focal->id,
            'filters' => ['k' => $query->semanticK, 'max_distance' => $query->maxDistance],
        ]);
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function buildVault(ThoughtGraphQuery $query): array
    {
        $warnings = [];
        $base = $this->vaultSeedQuery($query);
        $total = (clone $base)->count();
        $thoughts = $base->orderByDesc('updated_at')->limit($query->limit)->get();
        $truncated = $total > $query->limit;

        $edges = [];

        if (in_array('thought_link', $query->layers, true)) {
            $memberIds = $thoughts->pluck('id')->all();
            $edges = array_merge($edges, $this->curatedEdges($memberIds, $query->userId));

            if ($query->includeNeighbors && $memberIds !== []) {
                $neighborThoughts = $this->neighborThoughts($memberIds, $query->userId, 50);
                $thoughts = $thoughts->merge($neighborThoughts)->unique('id')->values();
                $allIds = $thoughts->pluck('id')->all();
                $edges = array_merge($edges, $this->curatedEdges($allIds, $query->userId));
            }
        }

        if (in_array('parent_child', $query->layers, true) && ($query->includeChunks || $thoughts->contains(fn (Thought $t) => $t->parent_id !== null))) {
            $edges = array_merge($edges, $this->structuralEdges($thoughts));
        }

        if (in_array('shared_tag', $query->layers, true)) {
            if ($thoughts->count() > 60) {
                $warnings[] = 'shared_tag_skipped_node_cap';
            } else {
                $edges = array_merge($edges, $this->pairwiseSharedTagEdges($thoughts));
            }
        }

        if (in_array('semantic', $query->layers, true) && config('features.memory_graph_semantic')) {
            if ($thoughts->count() > 30) {
                $warnings[] = 'semantic_sampled';
                $sample = $thoughts->sortByDesc('updated_at')->take(30);
                $edges = array_merge($edges, $this->semanticEdgesAmongMembers($sample, $query));
            } else {
                $edges = array_merge($edges, $this->semanticEdgesAmongMembers($thoughts, $query));
            }
        }

        $nodes = $thoughts->map(fn (Thought $t) => $this->nodePayload($t, $t->parent_id !== null ? 'chunk' : 'member'))->all();

        return $this->assemble($nodes, $edges, [
            'mode' => 'vault',
            'focal_id' => null,
            'filters' => [
                'layers' => $query->layers,
                'project_id' => $query->projectId,
                'tag' => $query->tag,
                'source' => $query->source,
            ],
            'truncated' => $truncated,
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return list<array<string, mixed>>
     */
    private function edgesForThoughtSet(Collection $thoughts, ThoughtGraphQuery $query, ?Thought $focal = null): array
    {
        $thoughtIds = $thoughts->pluck('id')->all();
        $edges = $this->curatedEdges($thoughtIds, $query->userId, $query->linkTypes);

        if ($query->includeParentChild) {
            $edges = array_merge($edges, $this->structuralEdges($thoughts));
        }

        if ($query->includeSemantic && config('features.memory_graph_semantic')) {
            if ($focal !== null && $focal->embedding !== null) {
                $neighbors = Thought::query()
                    ->where('user_id', $query->userId)
                    ->whereIn('id', $thoughtIds)
                    ->where('id', '!=', $focal->id)
                    ->get();
                $edges = array_merge($edges, $this->semanticEdgesFromFocal($focal, $neighbors));
            } else {
                $edges = array_merge($edges, $this->semanticEdgesAmongMembers($thoughts, $query));
            }
        }

        return $edges;
    }

    /**
     * @return Collection<int, string>
     */
    private function collectThoughtLinkNeighborhood(string $focalId, int $userId, int $depth): Collection
    {
        $visited = collect([$focalId]);
        $frontier = collect([$focalId]);

        for ($hop = 0; $hop < $depth; $hop++) {
            if ($frontier->isEmpty()) {
                break;
            }

            $links = ThoughtLink::query()
                ->where('user_id', $userId)
                ->where(function ($q) use ($frontier) {
                    $q->whereIn('from_thought_id', $frontier)
                        ->orWhereIn('to_thought_id', $frontier);
                })
                ->get(['from_thought_id', 'to_thought_id']);

            $next = collect();
            foreach ($links as $link) {
                foreach ([$link->from_thought_id, $link->to_thought_id] as $id) {
                    if (! $visited->contains($id)) {
                        $next->push($id);
                        $visited->push($id);
                    }
                }
            }

            $frontier = $next->reject(fn (string $id) => $id === $focalId)->values();
        }

        return $visited->reject(fn (string $id) => $id === $focalId)->values();
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return Collection<int, Thought>
     */
    private function appendParentChildNodes(Collection $thoughts, Thought $focal, ThoughtGraphQuery $query): Collection
    {
        if (! $query->includeParentChild) {
            return $thoughts;
        }

        $extra = collect();

        if ($focal->parent_id) {
            $parent = Thought::query()->where('user_id', $query->userId)->find($focal->parent_id);
            if ($parent) {
                $extra->push($parent);
            }
        }

        $extra = $extra->merge(
            Thought::query()->where('user_id', $query->userId)->where('parent_id', $focal->id)->get()
        );

        return $thoughts->merge($extra);
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return Collection<int, Thought>
     */
    private function appendHierarchyForMembers(Collection $thoughts, int $userId): Collection
    {
        $extra = collect();

        foreach ($thoughts as $thought) {
            if ($thought->parent_id) {
                $parent = Thought::query()->where('user_id', $userId)->find($thought->parent_id);
                if ($parent) {
                    $extra->push($parent);
                }
            }
            $extra = $extra->merge(
                Thought::query()->where('user_id', $userId)->where('parent_id', $thought->id)->get()
            );
        }

        return $thoughts->merge($extra);
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return Collection<int, Thought>
     */
    private function filterChunksForFocal(Collection $thoughts, Thought $focal, bool $includeChunks): Collection
    {
        if ($includeChunks) {
            return $thoughts;
        }

        return $thoughts->filter(function (Thought $thought) use ($focal) {
            if ($thought->id === $focal->id) {
                return true;
            }

            return $thought->parent_id === null;
        })->values();
    }

    /**
     * @param  list<string>  $memberIds
     * @return Collection<int, Thought>
     */
    private function neighborThoughts(array $memberIds, int $userId, int $cap): Collection
    {
        $links = ThoughtLink::query()
            ->where('user_id', $userId)
            ->where(function ($q) use ($memberIds) {
                $q->whereIn('from_thought_id', $memberIds)
                    ->orWhereIn('to_thought_id', $memberIds);
            })
            ->get();

        $neighborIds = collect();
        foreach ($links as $link) {
            if (! in_array($link->from_thought_id, $memberIds, true)) {
                $neighborIds->push($link->from_thought_id);
            }
            if (! in_array($link->to_thought_id, $memberIds, true)) {
                $neighborIds->push($link->to_thought_id);
            }
        }

        $neighborIds = $neighborIds->unique()->take($cap);
        if ($neighborIds->isEmpty()) {
            return collect();
        }

        return Thought::query()
            ->where('user_id', $userId)
            ->whereIn('id', $neighborIds)
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @param  list<string>  $thoughtIds
     * @param  list<string>  $linkTypes
     * @return list<array<string, mixed>>
     */
    private function curatedEdges(array $thoughtIds, int $userId, array $linkTypes = []): array
    {
        if ($thoughtIds === []) {
            return [];
        }

        $query = ThoughtLink::query()
            ->where('user_id', $userId)
            ->whereIn('from_thought_id', $thoughtIds)
            ->whereIn('to_thought_id', $thoughtIds);

        if ($linkTypes !== []) {
            $query->whereIn('link_type', $linkTypes);
        }

        return $query->get()->map(function (ThoughtLink $link) {
            $type = ThoughtLinkType::tryFrom($link->link_type);
            $label = $type?->label() ?? $link->link_type;

            return [
                'id' => 'link:'.$link->id,
                'from' => $link->from_thought_id,
                'to' => $link->to_thought_id,
                'edge_type' => 'thought_link',
                'label' => $label,
                'directed' => true,
                'dashed' => false,
                'link_type' => $link->link_type,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return list<array<string, mixed>>
     */
    private function structuralEdges(Collection $thoughts): array
    {
        $edges = [];
        $byId = $thoughts->keyBy('id');

        foreach ($thoughts as $thought) {
            if ($thought->parent_id === null || ! $byId->has($thought->parent_id)) {
                continue;
            }

            $edges[] = [
                'id' => 'parent:'.$thought->parent_id.':'.$thought->id,
                'from' => $thought->parent_id,
                'to' => $thought->id,
                'edge_type' => 'parent_child',
                'label' => 'child of',
                'directed' => true,
                'dashed' => false,
            ];
        }

        return $edges;
    }

    /**
     * @param  Collection<int, Thought>  $members
     * @return list<array<string, mixed>>
     */
    private function semanticEdgesAmongMembers(Collection $members, ThoughtGraphQuery $query): array
    {
        $edges = [];
        $existingPairs = [];

        foreach ($members as $member) {
            if ($member->embedding === null) {
                continue;
            }

            $neighbors = Thought::query()
                ->where('user_id', $query->userId)
                ->whereIn('id', $members->pluck('id'))
                ->where('id', '!=', $member->id)
                ->whereNotNull('embedding')
                ->nearestWithin($member->embedding, $query->maxDistance)
                ->limit($query->semanticK)
                ->get();

            foreach ($neighbors as $neighbor) {
                $pairKey = $this->undirectedPairKey($member->id, $neighbor->id);
                if (isset($existingPairs[$pairKey])) {
                    continue;
                }
                $existingPairs[$pairKey] = true;
                $edges[] = [
                    'id' => 'semantic:'.$pairKey,
                    'from' => $member->id,
                    'to' => $neighbor->id,
                    'edge_type' => 'semantic',
                    'label' => 'similar',
                    'directed' => false,
                    'dashed' => true,
                ];
            }
        }

        return $edges;
    }

    /**
     * @param  Collection<int, Thought>  $neighbors
     * @return list<array<string, mixed>>
     */
    private function semanticEdgesFromFocal(Thought $focal, Collection $neighbors): array
    {
        $edges = [];
        foreach ($neighbors as $neighbor) {
            $edges[] = [
                'id' => 'semantic:'.$focal->id.':'.$neighbor->id,
                'from' => $focal->id,
                'to' => $neighbor->id,
                'edge_type' => 'semantic',
                'label' => 'similar',
                'directed' => false,
                'dashed' => true,
            ];
        }

        return $edges;
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return list<array<string, mixed>>
     */
    private function pairwiseSharedTagEdges(Collection $thoughts): array
    {
        $edges = [];
        $list = $thoughts->values();

        for ($i = 0; $i < $list->count(); $i++) {
            $tagsA = $this->thoughtTags($list[$i]);
            for ($j = $i + 1; $j < $list->count(); $j++) {
                $shared = array_intersect($tagsA, $this->thoughtTags($list[$j]));
                if (count($shared) >= 2) {
                    $edges[] = [
                        'id' => 'shared-tag:'.$list[$i]->id.':'.$list[$j]->id,
                        'from' => $list[$i]->id,
                        'to' => $list[$j]->id,
                        'edge_type' => 'shared_tag',
                        'label' => implode(', ', array_slice($shared, 0, 2)),
                        'directed' => false,
                        'dashed' => true,
                    ];
                }
            }
        }

        return $edges;
    }

    /**
     * @return list<string>
     */
    private function thoughtTags(Thought $thought): array
    {
        $tags = $thought->metadata['tags'] ?? [];

        return is_array($tags) ? array_values($tags) : [];
    }

    private function undirectedPairKey(string $a, string $b): string
    {
        return $a < $b ? $a.':'.$b : $b.':'.$a;
    }

    private function taggedThoughtsQuery(int $userId, string $tag, ?string $since): Builder
    {
        $query = Thought::query()
            ->where('user_id', $userId)
            ->visibleInStream()
            ->tagMatchesQuery(mb_strtolower(trim($tag)));

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query;
    }

    private function vaultSeedQuery(ThoughtGraphQuery $query): Builder
    {
        $builder = Thought::query()
            ->where('user_id', $query->userId)
            ->visibleInStream();

        if (! $query->includeChunks) {
            $builder->whereNull('parent_id');
        }

        if ($query->source) {
            $builder->where('source', $query->source);
        }

        if ($query->since) {
            $builder->where('created_at', '>=', $query->since);
        }

        if ($query->until) {
            $builder->where('created_at', '<=', $query->until);
        }

        if ($query->tag) {
            $builder->tagMatchesQuery(mb_strtolower(trim($query->tag)));
        }

        if ($query->projectId) {
            $builder->whereHas('projects', fn (Builder $q) => $q->where('projects.id', $query->projectId));
        }

        return $builder;
    }

    /**
     * @return array<string, mixed>
     */
    private function nodePayload(Thought $thought, string $group): array
    {
        return [
            'id' => $thought->id,
            'label' => Str::limit($thought->content, 48),
            'group' => $group,
            'source_type' => $thought->source,
            'tags' => $this->thoughtTags($thought),
            'url' => route('thoughts.show', $thought),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, mixed>  $meta
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function assemble(array $nodes, array $edges, array $meta): array
    {
        $maxNodes = (int) ($meta['caps']['max_nodes'] ?? 200);
        $maxEdges = (int) ($meta['caps']['max_edges'] ?? 500);
        unset($meta['caps']);

        $truncated = (bool) ($meta['truncated'] ?? false);
        if (count($nodes) > $maxNodes) {
            $nodes = array_slice($nodes, 0, $maxNodes);
            $truncated = true;
        }

        $nodeIds = collect($nodes)->pluck('id')->flip()->all();
        $uniqueEdges = collect($edges)
            ->unique('id')
            ->filter(fn (array $e) => isset($nodeIds[$e['from']]) && isset($nodeIds[$e['to']]))
            ->values();

        if ($uniqueEdges->count() > $maxEdges) {
            $uniqueEdges = $uniqueEdges->take($maxEdges);
            $truncated = true;
        }

        return [
            'nodes' => array_values($nodes),
            'edges' => $uniqueEdges->all(),
            'meta' => array_merge([
                'node_count' => count($nodes),
                'edge_count' => $uniqueEdges->count(),
                'truncated' => $truncated,
                'caps' => ['max_nodes' => $maxNodes, 'max_edges' => $maxEdges],
            ], $meta),
        ];
    }
}
