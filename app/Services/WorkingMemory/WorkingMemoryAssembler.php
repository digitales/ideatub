<?php

namespace App\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class WorkingMemoryAssembler
{
    private const FRESH_WINDOW_HOURS = 4;

    private const STALE_WINDOW_HOURS = 24;

    private const OVERLAY_THREAD_LIMIT = 5;

    public function __construct(
        private readonly WorkingMemoryScopeNormalizer $scopeNormalizer,
        private readonly WorkingMemoryConsolidationWindowResolver $consolidationWindowResolver,
    ) {}

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return array{
     *     executive_summary: string,
     *     key_concepts: array<int, array{title: string}>,
     *     active_threads: array<int, array{title: string}>,
     *     open_questions: array<int, array{question: string}>,
     *     next_actions: array<int, array{action: string}>,
     *     confidence_score: float
     * }
     */
    public function assemblePayload(Collection $thoughts): array
    {
        $tagCounts = $this->countTags($thoughts);
        $keyConcepts = array_map(
            fn (string $tag): array => ['title' => $tag],
            array_slice(array_keys($tagCounts), 0, 5)
        );

        $activeThreads = $thoughts
            ->pluck('content')
            ->filter(fn ($content): bool => is_string($content) && trim($content) !== '')
            ->map(fn (string $content): array => ['title' => Str::limit(trim($content), 90)])
            ->unique('title')
            ->take(5)
            ->values()
            ->all();

        $openQuestions = $thoughts
            ->pluck('content')
            ->filter(fn ($content): bool => is_string($content) && str_contains($content, '?'))
            ->map(fn (string $content): array => ['question' => Str::finish(Str::limit(trim($content), 90, ''), '?')])
            ->unique('question')
            ->take(5)
            ->values()
            ->all();

        $nextActions = $thoughts
            ->pluck('content')
            ->filter(fn ($content): bool => is_string($content) && trim($content) !== '')
            ->map(fn (string $content): array => ['action' => Str::limit(trim($content), 90)])
            ->unique('action')
            ->take(5)
            ->values()
            ->all();

        $thoughtCount = $thoughts->count();
        $confidenceScore = $this->boundConfidence(25 + ($thoughtCount * 2.5) + (count($keyConcepts) * 8));

        if ($keyConcepts === []) {
            $keyConcepts = [['title' => 'No key concepts identified yet']];
        }
        if ($activeThreads === []) {
            $activeThreads = [['title' => 'No active threads identified yet']];
        }
        if ($openQuestions === []) {
            $openQuestions = [['question' => 'What information is still missing?']];
        }
        if ($nextActions === []) {
            $nextActions = [['action' => 'Capture more thoughts to improve memory coverage']];
        }

        return [
            'executive_summary' => $this->executiveSummary($thoughtCount, $keyConcepts),
            'key_concepts' => $keyConcepts,
            'active_threads' => $activeThreads,
            'open_questions' => $openQuestions,
            'next_actions' => $nextActions,
            'confidence_score' => $confidenceScore,
        ];
    }

    /**
     * @param  array{
     *     executive_summary: string,
     *     key_concepts: array<int, array{title: string}>,
     *     active_threads: array<int, array{title: string}>,
     *     open_questions: array<int, array{question: string}>,
     *     next_actions: array<int, array{action: string}>
     * }  $payload
     */
    public function renderSummary(array $payload): string
    {
        return implode("\n\n", [
            '## Executive summary',
            $payload['executive_summary'],
            '## Key concepts',
            $this->renderBullets($payload['key_concepts'], 'title'),
            '## Active threads',
            $this->renderBullets($payload['active_threads'], 'title'),
            '## Open questions',
            $this->renderBullets($payload['open_questions'], 'question'),
            '## Next actions',
            $this->renderBullets($payload['next_actions'], 'action'),
        ]);
    }

    public function boundConfidence(float $confidenceScore): float
    {
        return max(0.0, min(100.0, round($confidenceScore, 2)));
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return array<string, int>
     */
    private function countTags(Collection $thoughts): array
    {
        $counts = [];

        foreach ($thoughts as $thought) {
            $tags = data_get($thought->metadata, 'tags');
            if (! is_array($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                $normalizedTag = Str::of((string) $tag)->trim()->lower()->toString();
                if ($normalizedTag === '') {
                    continue;
                }

                $counts[$normalizedTag] = ($counts[$normalizedTag] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function renderBullets(array $rows, string $key): string
    {
        return collect($rows)
            ->map(fn (array $row): string => '- '.(string) ($row[$key] ?? ''))
            ->implode("\n");
    }

    /**
     * @param  array<int, array{title: string}>  $keyConcepts
     */
    private function executiveSummary(int $thoughtCount, array $keyConcepts): string
    {
        $topConcept = $keyConcepts[0]['title'] ?? 'unclassified topics';

        return "First-pass synthesis across {$thoughtCount} thoughts highlights {$topConcept} as the strongest signal.";
    }

    /**
     * Assemble the canonical API payload for a user's working memory at the given scope.
     *
     * structured_sections maps section titles to lists of structured bullets (id, text,
     * importance, fallback_mode, citations). Each citation includes required type, url, and
     * label, plus optional thought_id, source_ref, and confidence when persisted.
     *
     * @return array{
     *     scope_type: string,
     *     scope_key: string,
     *     freshness_state: string,
     *     confidence_score: float,
     *     summary_markdown: string,
     *     key_concepts: array<int, array{title: string}>,
     *     active_threads: array<int, array{title: string}>,
     *     open_questions: array<int, array{question: string}>,
     *     next_actions: array<int, array{action: string}>,
     *     structured_sections: array<string, array<int, array<string, mixed>>>,
     *     references: array<int, array{type: string, url: string, label: string}>,
     *     section_references: array<string, array<int, array<string, mixed>>>,
     *     citation_coverage: float|null,
     *     authoring_status: string|null,
     *     validation_error: string|null,
     *     build_diagnostics: array{required_items: int, cited_items: int, reason_codes: array<int, string>}|null,
     *     last_refreshed_at: string|null,
     *     effective_consolidation_window_days: int,
     *     baseline_build_type: string,
     *     overlay_deltas: array<int, array{label: string, detail: string, since: string|null}>,
     *     input_count: int
     * }
     */
    public function forScope(int $userId, string $scopeType, string $scopeKey): array
    {
        [$normalizedScopeType, $normalizedScopeKey] = $this->scopeNormalizer->normalize($scopeType, $scopeKey);

        $memory = WorkingMemory::query()
            ->where('user_id', $userId)
            ->where('scope_type', $normalizedScopeType)
            ->where('scope_key', $normalizedScopeKey)
            ->with('latestVersion')
            ->first();

        if ($memory !== null && $memory->latestVersion !== null) {
            return $this->payloadFromPersistedMemory($memory);
        }

        app(WorkingMemoryBuilderService::class)->buildConsolidated(
            $userId,
            $normalizedScopeType,
            $normalizedScopeKey
        );

        $memory = WorkingMemory::query()
            ->where('user_id', $userId)
            ->where('scope_type', $normalizedScopeType)
            ->where('scope_key', $normalizedScopeKey)
            ->with('latestVersion')
            ->firstOrFail();

        return $this->payloadFromPersistedMemory($memory);
    }

    /**
     * @return array{
     *     scope_type: string,
     *     scope_key: string,
     *     freshness_state: string,
     *     confidence_score: float,
     *     summary_markdown: string,
     *     key_concepts: array<int, array{title: string}>,
     *     active_threads: array<int, array{title: string}>,
     *     open_questions: array<int, array{question: string}>,
     *     next_actions: array<int, array{action: string}>,
     *     structured_sections: array<string, array<int, array<string, mixed>>>,
     *     references: array<int, array{type: string, url: string, label: string}>,
     *     section_references: array<string, array<int, array<string, mixed>>>,
     *     citation_coverage: float|null,
     *     authoring_status: string|null,
     *     validation_error: string|null,
     *     build_diagnostics: array{required_items: int, cited_items: int, reason_codes: array<int, string>}|null,
     *     last_refreshed_at: string|null,
     *     effective_consolidation_window_days: int,
     *     baseline_build_type: string,
     *     overlay_deltas: array<int, array{label: string, detail: string, since: string|null}>,
     *     input_count: int
     * }
     */
    private function payloadFromPersistedMemory(WorkingMemory $memory): array
    {
        $latestConsolidated = $memory->versions()
            ->where('build_type', 'consolidated')
            ->orderByDesc('created_at')
            ->first();

        $latestIncremental = $memory->versions()
            ->where('build_type', 'incremental')
            ->orderByDesc('created_at')
            ->first();

        $canonical = $this->resolveCanonicalVersion($memory, $latestConsolidated);

        return [
            'scope_type' => $memory->scope_type,
            'scope_key' => $memory->scope_key,
            'freshness_state' => $this->resolveFreshnessState($memory),
            'confidence_score' => (float) $canonical->confidence_score,
            'summary_markdown' => $canonical->summary_markdown,
            'key_concepts' => $canonical->key_concepts_json ?? [],
            'active_threads' => $canonical->active_threads_json ?? [],
            'open_questions' => $canonical->open_questions_json ?? [],
            'next_actions' => $canonical->next_actions_json ?? [],
            'structured_sections' => $canonical->structured_sections_json ?? [],
            'references' => $canonical->references_json ?? [],
            'section_references' => $canonical->section_references_json ?? [],
            'citation_coverage' => $canonical->citation_coverage !== null
                ? (float) $canonical->citation_coverage
                : null,
            'authoring_status' => $canonical->authoring_status,
            'validation_error' => $canonical->validation_error,
            'build_diagnostics' => $canonical->build_diagnostics_json ?? null,
            'last_refreshed_at' => $memory->last_refreshed_at?->toIso8601String(),
            'effective_consolidation_window_days' => $this->consolidationWindowResolver->effectiveDaysForUserId((int) $memory->user_id),
            'baseline_build_type' => (string) $canonical->build_type,
            'overlay_deltas' => $this->buildOverlayDeltas($latestIncremental, $latestConsolidated),
            'input_count' => $canonical->inputs()->count(),
        ];
    }

    private function resolveCanonicalVersion(WorkingMemory $memory, ?WorkingMemoryVersion $latestConsolidated): WorkingMemoryVersion
    {
        if ($latestConsolidated !== null) {
            return $latestConsolidated;
        }

        $latest = $memory->latestVersion;
        if ($latest === null) {
            throw new RuntimeException('Working memory is missing a latest version.');
        }

        return $latest;
    }

    /**
     * @return array<int, array{label: string, detail: string, since: string|null}>
     */
    private function buildOverlayDeltas(?WorkingMemoryVersion $incremental, ?WorkingMemoryVersion $consolidated): array
    {
        if ($incremental === null) {
            return [];
        }

        if ($consolidated !== null
            && $incremental->created_at !== null
            && $consolidated->created_at !== null
            && $incremental->created_at->lessThanOrEqualTo($consolidated->created_at)) {
            return [];
        }

        $threads = $incremental->active_threads_json ?? [];
        if (! is_array($threads)) {
            return [];
        }

        $since = $incremental->created_at?->toIso8601String();

        $deltas = [];
        foreach (array_slice($threads, 0, self::OVERLAY_THREAD_LIMIT) as $thread) {
            if (! is_array($thread)) {
                continue;
            }

            $title = trim((string) ($thread['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $detailRaw = trim((string) ($thread['detail'] ?? ''));

            $deltas[] = [
                'label' => Str::limit($title, 120),
                'detail' => $detailRaw !== '' ? Str::limit($detailRaw, 200) : '',
                'since' => $since,
            ];
        }

        return $deltas;
    }

    /**
     * Age-based freshness: fresh if refreshed in <4 hours, degraded from 4-<24 hours, stale at >=24 hours or if never refreshed.
     */
    public function freshnessFromAge(?Carbon $lastRefreshedAt): string
    {
        if ($lastRefreshedAt === null) {
            return 'stale';
        }

        if ($lastRefreshedAt->lessThanOrEqualTo(now()->subHours(self::STALE_WINDOW_HOURS))) {
            return 'stale';
        }

        if ($lastRefreshedAt->lessThanOrEqualTo(now()->subHours(self::FRESH_WINDOW_HOURS))) {
            return 'degraded';
        }

        return 'fresh';
    }

    private function resolveFreshnessState(WorkingMemory $memory): string
    {
        $fromAge = $this->freshnessFromAge($memory->last_refreshed_at);

        if (($memory->freshness_state ?? '') === 'degraded') {
            return $this->worstFreshness($fromAge, 'degraded');
        }

        return $fromAge;
    }

    private function worstFreshness(string $fromAge, string $fromDb): string
    {
        $rank = [
            'fresh' => 1,
            'degraded' => 2,
            'stale' => 3,
        ];

        $rankA = $rank[$fromAge] ?? 2;
        $rankB = $rank[$fromDb] ?? 2;

        return $rankA >= $rankB ? $fromAge : $fromDb;
    }
}
