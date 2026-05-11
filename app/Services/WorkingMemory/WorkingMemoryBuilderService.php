<?php

namespace App\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WorkingMemoryBuilderService
{
    public function __construct(
        private readonly WorkingMemoryAssembler $assembler,
        private readonly WorkingMemoryScopeNormalizer $scopeNormalizer,
        private readonly WorkingMemoryConsolidationWindowResolver $consolidationWindowResolver,
        private readonly MemoryInsightsService $memoryInsightsService,
        private readonly WorkingMemoryEvidencePackBuilder $evidencePackBuilder,
        private readonly WorkingMemoryAiAuthorService $aiAuthorService,
        private readonly WorkingMemoryOutputValidator $outputValidator,
        private readonly WorkingMemoryLegacyRowCitationResolver $legacyRowCitationResolver,
    ) {}

    public function buildConsolidated(int $userId, string $scopeType, string $scopeKey): WorkingMemoryVersion
    {
        return $this->build($userId, $scopeType, $scopeKey, 'consolidated');
    }

    public function buildIncremental(int $userId, string $scopeType, string $scopeKey): WorkingMemoryVersion
    {
        return $this->build($userId, $scopeType, $scopeKey, 'incremental');
    }

    private function build(int $userId, string $scopeType, string $scopeKey, string $buildType): WorkingMemoryVersion
    {
        [$normalizedScopeType, $normalizedScopeKey] = $this->scopeNormalizer->normalize($scopeType, $scopeKey);

        $memory = WorkingMemory::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'scope_type' => $normalizedScopeType,
                'scope_key' => $normalizedScopeKey,
            ],
            [
                'freshness_state' => 'stale',
            ]
        );
        $memory->forceFill(['build_started_at' => now()])->save();

        $thoughts = $this->selectThoughts($userId, $normalizedScopeType, $normalizedScopeKey, $buildType);
        $structuredSections = null;
        $references = null;
        $sectionReferences = [];
        $citationCoverage = null;
        $authoringStatus = 'disabled';
        $validationError = null;
        $buildDiagnostics = null;

        try {
            if ($this->authoringEnabled()) {
                $evidencePack = $this->evidencePackBuilder->build(
                    $userId,
                    $normalizedScopeType,
                    $normalizedScopeKey,
                    $thoughts
                );

                $authoredOutput = $this->aiAuthorService->authorFromEvidence($evidencePack);
                $validation = $this->outputValidator->validate(
                    $authoredOutput,
                    (float) config('working_memory.citation_min_coverage', 0.90),
                    count(is_array($evidencePack['compactions'] ?? null) ? $evidencePack['compactions'] : [])
                );

                $buildDiagnostics = $validation['diagnostics'] ?? null;

                $structuredSections = $this->normalizeStructuredSections($authoredOutput['structured_sections'] ?? null);
                $references = $this->normalizeReferences($authoredOutput['references'] ?? null);
                $sectionReferences = $this->buildSectionReferences(
                    $structuredSections,
                    $references,
                    $normalizedScopeType,
                    $normalizedScopeKey
                );
                $citationCoverage = $validation['coveragePercent'] ?? null;

                if (($validation['ok'] ?? false) === true) {
                    $summaryMarkdown = (string) ($authoredOutput['summary_markdown'] ?? '');
                    $payload = $this->payloadFromStructuredSections($structuredSections, $thoughts);
                    $authoringStatus = 'validated';
                    $buildDiagnostics = $this->mergeCompactionDiagnostics(
                        $buildDiagnostics,
                        $evidencePack,
                    );
                } elseif (($validation['failure_type'] ?? null) === 'soft') {
                    [$payload, $summaryMarkdown] = $this->legacyPayloadAndSummary($normalizedScopeType, $thoughts);
                    $authoringStatus = 'fallback';
                    $validationError = (string) ($validation['message'] ?? 'AI-authored output failed validation.');
                    $structuredSections = null;
                    $references = [];
                    $sectionReferences = [];
                    $citationCoverage = null;
                } else {
                    throw new \RuntimeException((string) ($validation['message'] ?? 'AI-authored output failed hard validation.'));
                }
            } else {
                [$payload, $summaryMarkdown] = $this->legacyPayloadAndSummary($normalizedScopeType, $thoughts);
            }
        } catch (Throwable $e) {
            Log::warning('WorkingMemoryBuilderService: build failed, attempting fallback.', [
                'user_id' => $userId,
                'scope_type' => $normalizedScopeType,
                'scope_key' => $normalizedScopeKey,
                'build_type' => $buildType,
                'message' => $e->getMessage(),
            ]);

            $fallbackVersion = $this->lastKnownGoodVersion($userId, $normalizedScopeType, $normalizedScopeKey);
            if ($fallbackVersion !== null) {
                return $fallbackVersion;
            }

            [$payload, $summaryMarkdown] = $this->legacyPayloadAndSummary($normalizedScopeType, $thoughts);
            $authoringStatus = 'fallback';
            $validationError = $e->getMessage();
            $structuredSections = null;
            $references = [];
            $sectionReferences = [];
            $citationCoverage = null;
        }

        return DB::transaction(function () use (
            $memory,
            $buildType,
            $thoughts,
            $payload,
            $summaryMarkdown,
            $structuredSections,
            $references,
            $sectionReferences,
            $citationCoverage,
            $authoringStatus,
            $validationError,
            $buildDiagnostics,
        ): WorkingMemoryVersion {
            $version = $memory->versions()->create([
                'build_type' => $buildType,
                'summary_markdown' => $summaryMarkdown,
                'key_concepts_json' => $payload['key_concepts'],
                'active_threads_json' => $payload['active_threads'],
                'open_questions_json' => $payload['open_questions'],
                'next_actions_json' => $payload['next_actions'],
                'structured_sections_json' => $structuredSections,
                'references_json' => $references,
                'section_references_json' => $sectionReferences,
                'build_diagnostics_json' => $buildDiagnostics,
                'citation_coverage' => $citationCoverage,
                'authoring_status' => $authoringStatus,
                'validation_error' => $validationError,
                'confidence_score' => $this->assembler->boundConfidence((float) ($payload['confidence_score'] ?? 0)),
                'source_window_start' => $thoughts->min('created_at'),
                'source_window_end' => $thoughts->max('created_at'),
            ]);

            $version->inputs()->createMany(
                $thoughts->values()->map(function (Thought $thought, int $index): array {
                    $weight = max(0.1, 1.0 - ($index * 0.1));

                    return [
                        'thought_id' => $thought->id,
                        'contribution_type' => $index < 5 ? 'primary' : 'supporting',
                        'weight' => round($weight, 2),
                    ];
                })->all()
            );

            $memory->forceFill([
                'latest_version_id' => $version->id,
                'freshness_state' => $this->assembler->freshnessFromAge(now()),
                'last_refreshed_at' => now(),
                'build_started_at' => null,
            ])->save();

            return $version->fresh(['workingMemory', 'inputs']);
        });
    }

    private function lastKnownGoodVersion(int $userId, string $scopeType, string $scopeKey): ?WorkingMemoryVersion
    {
        $memory = WorkingMemory::query()
            ->where('user_id', $userId)
            ->where('scope_type', $scopeType)
            ->where('scope_key', $scopeKey)
            ->first();

        if ($memory === null || $memory->latest_version_id === null) {
            return null;
        }

        $fallbackVersion = WorkingMemoryVersion::query()
            ->whereKey($memory->latest_version_id)
            ->with(['workingMemory', 'inputs'])
            ->first();

        if ($fallbackVersion === null) {
            return null;
        }

        $memory->forceFill([
            'freshness_state' => 'degraded',
            'build_started_at' => null,
        ])->save();

        return $fallbackVersion;
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function legacyPayloadAndSummary(string $scopeType, Collection $thoughts): array
    {
        if ($scopeType === 'insights') {
            $synthesis = $this->memoryInsightsService->synthesizePersistable($thoughts);

            return [[
                'key_concepts' => $synthesis['key_concepts'],
                'active_threads' => $synthesis['active_threads'],
                'open_questions' => $synthesis['open_questions'],
                'next_actions' => $synthesis['next_actions'],
                'confidence_score' => $synthesis['confidence_score'],
            ], $synthesis['summary_markdown']];
        }

        $payload = $this->assembler->assemblePayload($thoughts);

        return [$payload, $this->assembler->renderSummary($payload)];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function normalizeStructuredSections(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        $normalized = [];
        foreach ($sections as $section => $bullets) {
            $sectionName = trim((string) $section);
            if ($sectionName === '') {
                continue;
            }

            $items = [];
            foreach (is_array($bullets) ? $bullets : [$bullets] as $entry) {
                $item = $this->normalizeStructuredSectionItem($entry);
                if ($item !== null) {
                    $items[] = $item;
                }
            }

            if ($items !== []) {
                $normalized[$sectionName] = array_values($items);
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeStructuredSectionItem(mixed $entry): ?array
    {
        if (is_string($entry)) {
            $text = trim($entry);
            if ($text === '') {
                return null;
            }

            return [
                'id' => (string) Str::uuid(),
                'text' => $text,
                'importance' => 0,
                'fallback_mode' => 'direct',
                'citations' => [],
            ];
        }

        if (! is_array($entry)) {
            return null;
        }

        $text = trim((string) ($entry['text'] ?? ''));
        if ($text === '') {
            return null;
        }

        $id = trim((string) ($entry['id'] ?? ''));
        if ($id === '') {
            $id = (string) Str::uuid();
        }

        $rawMode = $entry['fallback_mode'] ?? 'direct';
        $fallbackMode = $rawMode === 'section_bundle' ? 'section_bundle' : 'direct';

        return [
            'id' => $id,
            'text' => $text,
            'importance' => (int) ($entry['importance'] ?? 0),
            'fallback_mode' => $fallbackMode,
            'citations' => $this->normalizeCitationEntries($entry['citations'] ?? null),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCitationEntries(mixed $citations): array
    {
        if (! is_array($citations)) {
            return [];
        }

        $normalized = [];
        foreach ($citations as $citation) {
            if (! is_array($citation)) {
                continue;
            }

            $entry = $this->normalizedCitationRow($citation);
            if ($entry !== null) {
                $normalized[] = $entry;
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizedCitationRow(array $citation): ?array
    {
        $url = trim((string) ($citation['url'] ?? ''));
        $label = trim((string) ($citation['label'] ?? ''));
        if ($url === '' || $label === '') {
            return null;
        }

        $type = trim((string) ($citation['type'] ?? ''));
        $row = [
            'type' => $type !== '' ? $type : 'source',
            'url' => $url,
            'label' => $label,
        ];

        if (array_key_exists('thought_id', $citation)) {
            $thoughtId = $citation['thought_id'];
            if ($thoughtId !== null && $thoughtId !== '') {
                $row['thought_id'] = is_string($thoughtId) ? $thoughtId : (string) $thoughtId;
            }
        }

        if (array_key_exists('source_ref', $citation)) {
            $sourceRef = $citation['source_ref'];
            if ($sourceRef !== null && $sourceRef !== '') {
                $row['source_ref'] = is_string($sourceRef) ? $sourceRef : (string) $sourceRef;
            }
        }

        if (array_key_exists('confidence', $citation) && is_numeric($citation['confidence'])) {
            $row['confidence'] = (float) $citation['confidence'];
        }

        return $row;
    }

    /**
     * @return array<int, string>
     */
    private function sectionTextsForPayload(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $texts = [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $text = trim($entry);
                if ($text !== '') {
                    $texts[] = $text;
                }

                continue;
            }

            if (is_array($entry)) {
                $text = trim((string) ($entry['text'] ?? ''));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        return $texts;
    }

    /**
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function normalizeReferences(mixed $references): array
    {
        if (! is_array($references)) {
            return [];
        }

        return collect($references)
            ->filter(fn ($reference): bool => is_array($reference))
            ->map(function (array $reference): array {
                return [
                    'type' => trim((string) ($reference['type'] ?? 'source')) ?: 'source',
                    'url' => trim((string) ($reference['url'] ?? '')),
                    'label' => trim((string) ($reference['label'] ?? '')),
                ];
            })
            ->filter(fn (array $reference): bool => $reference['url'] !== '' && $reference['label'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     * @param  array<int, array<string, mixed>>  $references
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildSectionReferences(array $sections, array $references, string $scopeType, string $scopeKey): array
    {
        if ($sections === []) {
            return [];
        }

        return collect($sections)
            ->mapWithKeys(function (mixed $items, mixed $section) use ($references, $scopeType, $scopeKey): array {
                $sectionName = trim((string) $section);
                if ($sectionName === '') {
                    return [];
                }

                $sectionReferences = [];
                $seen = [];

                foreach (is_array($items) ? $items : [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    foreach ($this->normalizeCitationEntries($item['citations'] ?? null) as $citation) {
                        $this->pushUniqueValidReference($sectionReferences, $seen, $citation);
                    }
                }

                if ($sectionReferences === []) {
                    foreach (array_slice($references, 0, 3) as $reference) {
                        $this->pushUniqueValidReference($sectionReferences, $seen, $reference);
                    }
                }

                array_unshift($sectionReferences, [
                    'type' => 'stream_filter',
                    'url' => $this->sectionStreamFilterUrl($scopeType, $scopeKey, $sectionName),
                    'label' => $sectionName.' evidence',
                ]);

                return [$sectionName => array_values($sectionReferences)];
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $references
     * @param  array<string, true>  $seen
     * @param  array<string, mixed>  $candidate
     */
    private function pushUniqueValidReference(array &$references, array &$seen, array $candidate): void
    {
        $signature = $this->referenceSignature($candidate);
        if ($signature === null || isset($seen[$signature])) {
            return;
        }

        $seen[$signature] = true;
        $references[] = $candidate;
    }

    private function referenceSignature(array $reference): ?string
    {
        $type = trim((string) ($reference['type'] ?? ''));
        $url = trim((string) ($reference['url'] ?? ''));
        $label = trim((string) ($reference['label'] ?? ''));
        if ($url === '' || $label === '' || ! $this->isSupportedSectionReferenceUrl($url)) {
            return null;
        }

        return $type.'|'.$url.'|'.$label;
    }

    private function isSupportedSectionReferenceUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        if ($this->containsParentTraversalSegments($url)) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return $scheme === '';
        }

        return trim((string) ($parts['host'] ?? '')) !== '';
    }

    private function containsParentTraversalSegments(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return true;
        }

        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if ($path === '') {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function sectionStreamFilterUrl(string $scopeType, string $scopeKey, string $sectionName): string
    {
        return '/stream?'.http_build_query([
            'scope_type' => $scopeType,
            'scope_key' => $scopeKey,
            'section' => $sectionName,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     * @param  Collection<int, Thought>  $thoughts
     * @return array{
     *     key_concepts: array<int, array{title: string}>,
     *     active_threads: array<int, array<string, string>>,
     *     open_questions: array<int, array<string, string>>,
     *     next_actions: array<int, array<string, string>>,
     *     confidence_score: float
     * }
     */
    private function payloadFromStructuredSections(array $sections, Collection $thoughts): array
    {
        $legacyConfidence = $this->assembler->assemblePayload($thoughts)['confidence_score'];

        $keyConcepts = collect($this->sectionTextsForPayload($sections['Active Priorities'] ?? []))
            ->take(8)
            ->map(fn (string $entry): array => ['title' => $entry])
            ->values()
            ->all();

        $activeThreads = $this->legacyRowsFromStructuredSection($sections['Recent Changes'] ?? [], 'title', 8);
        $openQuestions = $this->legacyRowsFromStructuredSection($sections['Open Questions'] ?? [], 'question', 8);
        $nextActions = $this->legacyRowsFromStructuredSection($sections['Next Actions'] ?? [], 'action', 8);

        return [
            'key_concepts' => $keyConcepts,
            'active_threads' => $activeThreads,
            'open_questions' => $openQuestions,
            'next_actions' => $nextActions,
            'confidence_score' => (float) $legacyConfidence,
        ];
    }

    /**
     * @param  mixed  $entries
     * @return array<int, array<string, string>>
     */
    private function legacyRowsFromStructuredSection($entries, string $fieldName, int $limit): array
    {
        $seen = [];
        $rows = [];
        foreach ($this->iterateStructuredSectionEntries(is_array($entries) ? $entries : []) as [$text, $citations]) {
            if ($text === '') {
                continue;
            }
            if (isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;

            $row = [$fieldName => $text];
            $link = $this->legacyRowCitationResolver->resolvePrimaryThought($citations);
            if ($link !== null) {
                $row['thought_id'] = $link['thought_id'];
                if (isset($link['url'])) {
                    $row['url'] = $link['url'];
                }
            }
            $rows[] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return \Generator<int, array{0: string, 1: array<int, mixed>}>
     */
    private function iterateStructuredSectionEntries(array $entries): \Generator
    {
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                yield [trim($entry), []];

                continue;
            }
            if (is_array($entry)) {
                $text = trim((string) ($entry['text'] ?? ''));
                $citations = $entry['citations'] ?? [];
                $citations = is_array($citations) ? $citations : [];
                yield [$text, $citations];
            }
        }
    }

    private function authoringEnabled(): bool
    {
        return (bool) config('features.working_memory_ai_authored')
            && (bool) config('working_memory.authoring_enabled');
    }

    /**
     * @return Collection<int, Thought>
     */
    private function selectThoughts(int $userId, string $scopeType, string $scopeKey, string $buildType): Collection
    {
        if ($scopeType === 'insights') {
            $thoughts = $this->memoryInsightsService->recentThoughtPool($userId);
        } else {
            $thoughts = Thought::query()
                ->where('user_id', $userId)
                ->visibleInStream()
                ->with('projects:id')
                ->orderByDesc('created_at')
                ->get();
        }

        $scoped = $thoughts->filter(function (Thought $thought) use ($scopeType, $scopeKey): bool {
            if ($scopeType === 'global') {
                return true;
            }

            if ($scopeType === 'insights') {
                return $this->memoryInsightsService->isResearchThought($thought);
            }

            if ($scopeType === 'tag') {
                $tags = collect(data_get($thought->metadata, 'tags', []))
                    ->map(fn ($tag): string => Str::of((string) $tag)->trim()->lower()->toString())
                    ->filter(fn (string $tag): bool => $tag !== '')
                    ->values();

                return $tags->containsStrict($scopeKey);
            }

            if ($scopeType !== 'project') {
                return false;
            }

            $metadataProject = Str::of((string) data_get($thought->source_metadata, 'project'))
                ->trim()
                ->lower()
                ->toString();

            $metadataMatch = $metadataProject !== '' && $metadataProject === $scopeKey;
            $linkedProjectMatch = $thought->projects->contains(
                fn ($project): bool => (string) $project->id === $scopeKey
            );

            return $metadataMatch || $linkedProjectMatch;
        })->values();

        if ($buildType === 'consolidated') {
            $days = $this->consolidationWindowResolver->effectiveDaysForUserId($userId);
            $cutoff = now()->subDays($days);

            return $scoped
                ->filter(function (Thought $thought) use ($cutoff): bool {
                    return $thought->created_at !== null && $thought->created_at->gte($cutoff);
                })
                ->values();
        }

        $windowed = $scoped
            ->filter(fn (Thought $thought): bool => $thought->created_at !== null && $thought->created_at->gte(now()->subDays(7)))
            ->values();

        if ($windowed->isNotEmpty()) {
            return $windowed->take(20)->values();
        }

        return $scoped->take(20)->values();
    }

    /**
     * Merge evidence-pack composition stats and the validator's normalized
     * citation counts into the build diagnostics payload.
     *
     * The coverage ratio reuses the validator's `cited_items` /
     * `compaction_cited_items`, which already account for bracket-marker
     * resolution and default-reference fallback. Walking raw
     * `structured_sections[].citations` here would underreport coverage when
     * the model uses `[n]` markers instead of explicit citation arrays.
     *
     * @param  array<string, mixed>|null  $diagnostics  validator-emitted diagnostics
     * @param  array<string, mixed>  $evidencePack
     * @return array<string, mixed>
     */
    private function mergeCompactionDiagnostics(
        ?array $diagnostics,
        array $evidencePack,
    ): array {
        $compactions = is_array($evidencePack['compactions'] ?? null) ? $evidencePack['compactions'] : [];
        $signals = is_array($evidencePack['signals'] ?? null) ? $evidencePack['signals'] : [];

        $subtypes = [];
        foreach ($compactions as $compaction) {
            $subtype = (string) ($compaction['subtype'] ?? '');
            if ($subtype !== '' && ! in_array($subtype, $subtypes, true)) {
                $subtypes[] = $subtype;
            }
        }

        $citedItems = (int) ($diagnostics['cited_items'] ?? 0);
        $compactionCitedItems = (int) ($diagnostics['compaction_cited_items'] ?? 0);
        $coverageRatio = $citedItems > 0
            ? round($compactionCitedItems / $citedItems, 4)
            : 0.0;

        return array_merge($diagnostics ?? [], [
            'compaction_inputs_count' => count($compactions),
            'compaction_subtypes_used' => $subtypes,
            'raw_thought_inputs_count' => count($signals),
            'compaction_coverage_ratio' => $coverageRatio,
        ]);
    }
}
