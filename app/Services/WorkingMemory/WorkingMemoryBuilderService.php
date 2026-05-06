<?php

namespace App\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

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

        $thoughts = $this->selectThoughts($userId, $normalizedScopeType, $normalizedScopeKey, $buildType);
        $structuredSections = null;
        $references = null;
        $citationCoverage = null;
        $authoringStatus = 'disabled';
        $validationError = null;

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
                    (float) config('working_memory.citation_min_coverage', 0.90)
                );

                $structuredSections = $this->normalizeStructuredSections($authoredOutput['structured_sections'] ?? null);
                $references = $this->normalizeReferences($authoredOutput['references'] ?? null);
                $citationCoverage = $validation['coveragePercent'] ?? null;

                if (($validation['ok'] ?? false) === true) {
                    $summaryMarkdown = (string) ($authoredOutput['summary_markdown'] ?? '');
                    $payload = $this->payloadFromStructuredSections($structuredSections, $citationCoverage);
                    $authoringStatus = 'validated';
                } elseif (($validation['failure_type'] ?? null) === 'soft') {
                    [$payload, $summaryMarkdown] = $this->legacyPayloadAndSummary($normalizedScopeType, $thoughts);
                    $authoringStatus = 'fallback';
                    $validationError = (string) ($validation['message'] ?? 'AI-authored output failed validation.');
                } else {
                    throw new RuntimeException((string) ($validation['message'] ?? 'AI-authored output failed hard validation.'));
                }
            } else {
                [$payload, $summaryMarkdown] = $this->legacyPayloadAndSummary($normalizedScopeType, $thoughts);
            }
        } catch (RuntimeException $e) {
            $fallbackVersion = $this->lastKnownGoodVersion($userId, $normalizedScopeType, $normalizedScopeKey);
            if ($fallbackVersion !== null) {
                return $fallbackVersion;
            }

            throw $e;
        }

        return DB::transaction(function () use (
            $userId,
            $normalizedScopeType,
            $normalizedScopeKey,
            $buildType,
            $thoughts,
            $payload,
            $summaryMarkdown,
            $structuredSections,
            $references,
            $citationCoverage,
            $authoringStatus,
            $validationError
        ): WorkingMemoryVersion {
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

            $version = $memory->versions()->create([
                'build_type' => $buildType,
                'summary_markdown' => $summaryMarkdown,
                'key_concepts_json' => $payload['key_concepts'],
                'active_threads_json' => $payload['active_threads'],
                'open_questions_json' => $payload['open_questions'],
                'next_actions_json' => $payload['next_actions'],
                'structured_sections_json' => $structuredSections,
                'references_json' => $references,
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
     * @return array<string, array<int, string>>
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

            $normalized[$sectionName] = collect(is_array($bullets) ? $bullets : [$bullets])
                ->map(fn ($bullet): string => trim((string) $bullet))
                ->filter(fn (string $bullet): bool => $bullet !== '')
                ->values()
                ->all();
        }

        return $normalized;
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
     * @param  array<string, array<int, string>>  $sections
     * @return array{
     *     key_concepts: array<int, array{title: string}>,
     *     active_threads: array<int, array{title: string}>,
     *     open_questions: array<int, array{question: string}>,
     *     next_actions: array<int, array{action: string}>,
     *     confidence_score: float
     * }
     */
    private function payloadFromStructuredSections(array $sections, ?float $citationCoverage): array
    {
        $keyConcepts = collect($sections['Active Priorities'] ?? [])
            ->take(8)
            ->map(fn (string $entry): array => ['title' => $entry])
            ->values()
            ->all();

        $activeThreads = collect($sections['Recent Changes'] ?? [])
            ->take(8)
            ->map(fn (string $entry): array => ['title' => $entry])
            ->values()
            ->all();

        $openQuestions = collect($sections['Open Questions'] ?? [])
            ->take(8)
            ->map(fn (string $entry): array => ['question' => $entry])
            ->values()
            ->all();

        $nextActions = collect($sections['Next Actions'] ?? [])
            ->take(8)
            ->map(fn (string $entry): array => ['action' => $entry])
            ->values()
            ->all();

        return [
            'key_concepts' => $keyConcepts,
            'active_threads' => $activeThreads,
            'open_questions' => $openQuestions,
            'next_actions' => $nextActions,
            'confidence_score' => (float) ($citationCoverage ?? 0.0),
        ];
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
}
