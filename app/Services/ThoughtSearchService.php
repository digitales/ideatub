<?php

namespace App\Services;

use App\Models\Thought;
use Illuminate\Support\Collection;

class ThoughtSearchService
{
    public function __construct(
        private OpenRouterService $openRouter,
    ) {}

    /**
     * Search thoughts: tag matches first (by created_at desc), then semantic (by distance). Dedupe by id.
     * Returns the full merged collection (no limit applied) so the web controller can paginate by slicing.
     *
     * @param  array{limit?: int, max_distance?: float, tag_limit?: int, semantic_limit?: int}  $options
     * @return array{thoughts: Collection<int, Thought>, total: int}
     */
    public function search(string $query, int $userId, array $options = []): array
    {
        $maxDistance = (float) ($options['max_distance'] ?? 0.5);
        $tagLimit = (int) ($options['tag_limit'] ?? 100);
        $semanticLimit = (int) ($options['semantic_limit'] ?? 100);

        $normalizedQuery = mb_strtolower(trim($query));

        $baseQuery = Thought::query()
            ->where('user_id', $userId)
            ->visibleInStream();

        $tagMatches = collect();
        if ($normalizedQuery !== '') {
            $tagMatches = (clone $baseQuery)
                ->tagMatchesQuery($normalizedQuery)
                ->orderByDesc('created_at')
                ->limit($tagLimit)
                ->get();
        }

        $tagIds = $tagMatches->pluck('id')->flip()->all();

        $embedding = $this->openRouter->embed($query);
        $semanticQuery = (clone $baseQuery)
            ->whereNotNull('embedding')
            ->nearestWithin($embedding, $maxDistance)
            ->limit($semanticLimit);

        $semantic = $semanticQuery->get();

        if ($semantic->isEmpty()) {
            $semantic = (clone $baseQuery)
                ->whereNotNull('embedding')
                ->nearestTo($embedding, $semanticLimit)
                ->get();
        }

        $semanticFiltered = $semantic->reject(fn (Thought $t) => isset($tagIds[$t->id]));

        $merged = $tagMatches->concat($semanticFiltered)->values();

        return [
            'thoughts' => $merged,
            'total' => $merged->count(),
        ];
    }
}
