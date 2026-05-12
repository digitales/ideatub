<?php

namespace App\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
     *     active_threads: array<int, array<string, string>>,
     *     open_questions: array<int, array<string, string>>,
     *     next_actions: array<int, array<string, string>>,
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

        $sorted = $thoughts->sortByDesc(fn (Thought $t): int => (int) ($t->created_at?->timestamp ?? 0))->values();

        $openQuestionIds = [];
        $openQuestions = [];
        $seenQuestionText = [];
        foreach ($sorted as $thought) {
            $content = $thought->content;
            if (! is_string($content) || ! str_contains($content, '?')) {
                continue;
            }
            $trimmed = trim($content);
            if ($trimmed === '') {
                continue;
            }
            $question = Str::finish(Str::limit($trimmed, 90, ''), '?');
            if (isset($seenQuestionText[$question])) {
                continue;
            }
            $seenQuestionText[$question] = true;
            $tid = (string) $thought->id;
            $openQuestionIds[$tid] = true;
            $openQuestions[] = ['question' => $question, 'thought_id' => $tid];
            if (count($openQuestions) >= 5) {
                break;
            }
        }

        $threadIds = [];
        $activeThreads = [];
        $seenThreadTitle = [];
        foreach ($sorted as $thought) {
            $tid = (string) $thought->id;
            if (isset($openQuestionIds[$tid])) {
                continue;
            }
            $content = $thought->content;
            if (! is_string($content)) {
                continue;
            }
            $trimmed = trim($content);
            if ($trimmed === '') {
                continue;
            }
            $title = Str::limit($trimmed, 90);
            if (isset($seenThreadTitle[$title])) {
                continue;
            }
            $seenThreadTitle[$title] = true;
            $threadIds[$tid] = true;
            $activeThreads[] = ['title' => $title, 'thought_id' => $tid];
            if (count($activeThreads) >= 5) {
                break;
            }
        }

        $nextActions = [];
        $seenActionText = [];
        foreach ($sorted as $thought) {
            $tid = (string) $thought->id;
            if (isset($openQuestionIds[$tid])) {
                continue;
            }
            if (isset($threadIds[$tid])) {
                continue;
            }
            $content = $thought->content;
            if (! is_string($content)) {
                continue;
            }
            $trimmed = trim($content);
            if ($trimmed === '') {
                continue;
            }
            $action = Str::limit($trimmed, 90);
            if (isset($seenActionText[$action])) {
                continue;
            }
            $seenActionText[$action] = true;
            $nextActions[] = ['action' => $action, 'thought_id' => $tid];
            if (count($nextActions) >= 5) {
                break;
            }
        }

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
     *     active_threads: array<int, array<string, string>>,
     *     open_questions: array<int, array<string, string>>,
     *     next_actions: array<int, array<string, string>>
     * }  $payload
     */
    public function renderSummary(array $payload): string
    {
        return implode("\n\n", [
            '## Executive summary',
            $payload['executive_summary'],
            '## Key concepts',
            $this->renderPlainBullets($payload['key_concepts'], 'title'),
            '## Active threads',
            $this->renderLegacyMarkdownBullets($payload['active_threads'], 'title'),
            '## Open questions',
            $this->renderLegacyMarkdownBullets($payload['open_questions'], 'question'),
            '## Next actions',
            $this->renderLegacyMarkdownBullets($payload['next_actions'], 'action'),
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
    private function renderPlainBullets(array $rows, string $key): string
    {
        return collect($rows)
            ->map(fn (array $row): string => '- '.(string) ($row[$key] ?? ''))
            ->implode("\n");
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function renderLegacyMarkdownBullets(array $rows, string $key): string
    {
        return collect($rows)
            ->map(fn (array $row): string => $this->legacyMarkdownBulletLine($row, $key))
            ->implode("\n");
    }

    /**
     * @param  array<string, string>  $row
     */
    private function legacyMarkdownBulletLine(array $row, string $key): string
    {
        $text = (string) ($row[$key] ?? '');
        $url = trim((string) ($row['url'] ?? ''));
        if ($url === '' && isset($row['thought_id'])) {
            $tid = trim((string) $row['thought_id']);
            if ($tid !== '' && Str::isUuid($tid)) {
                try {
                    $url = Route::has('thoughts.show')
                        ? route('thoughts.show', ['thought' => $tid])
                        : '';
                } catch (Throwable) {
                    $url = '';
                }
            }
        }
        if ($url !== '') {
            return '- ['.$this->escapeMarkdownLinkText($text).']('.$url.')';
        }

        return '- '.$text;
    }

    private function escapeMarkdownLinkText(string $text): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $text);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, string>>
     */
    private function enrichLegacyRowsWithUrls(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $merged = $row;
            $url = trim((string) ($merged['url'] ?? ''));
            $tid = isset($merged['thought_id']) ? trim((string) $merged['thought_id']) : '';
            if ($url === '' && $tid !== '' && Str::isUuid($tid)) {
                try {
                    if (Route::has('thoughts.show')) {
                        $merged['url'] = route('thoughts.show', ['thought' => $tid]);
                    }
                } catch (Throwable) {
                    // omit derived url
                }
            }
            $out[] = $merged;
        }

        return $out;
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
     *     active_threads: array<int, array<string, string>>,
     *     open_questions: array<int, array<string, string>>,
     *     next_actions: array<int, array<string, string>>,
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
     *     active_threads: array<int, array<string, string>>,
     *     open_questions: array<int, array<string, string>>,
     *     next_actions: array<int, array<string, string>>,
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
        $latestAuthoritative = $memory->versions()
            ->whereIn('build_type', ['consolidated', 'external'])
            ->orderByDesc('created_at')
            ->first();

        $latestIncremental = $memory->versions()
            ->where('build_type', 'incremental')
            ->orderByDesc('created_at')
            ->first();

        $canonical = $this->resolveCanonicalVersion($memory, $latestAuthoritative);

        return [
            'scope_type' => $memory->scope_type,
            'scope_key' => $memory->scope_key,
            'freshness_state' => $this->resolveFreshnessState($memory),
            'confidence_score' => (float) $canonical->confidence_score,
            'summary_markdown' => $canonical->summary_markdown,
            'key_concepts' => $canonical->key_concepts_json ?? [],
            'active_threads' => $this->enrichLegacyRowsWithUrls($canonical->active_threads_json ?? []),
            'open_questions' => $this->enrichLegacyRowsWithUrls($canonical->open_questions_json ?? []),
            'next_actions' => $this->enrichLegacyRowsWithUrls($canonical->next_actions_json ?? []),
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
            'overlay_deltas' => $this->buildOverlayDeltas($latestIncremental, $latestAuthoritative),
            'input_count' => $canonical->inputs()->count(),
        ];
    }

    private function resolveCanonicalVersion(WorkingMemory $memory, ?WorkingMemoryVersion $latestAuthoritative): WorkingMemoryVersion
    {
        if ($latestAuthoritative !== null) {
            return $latestAuthoritative;
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
