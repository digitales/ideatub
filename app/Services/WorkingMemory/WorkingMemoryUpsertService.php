<?php

namespace App\Services\WorkingMemory;

use App\Jobs\RetryWorkingMemorySupersedeJob;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class WorkingMemoryUpsertService
{
    private const KNOWN_SECTIONS = [
        'Current Focus',
        'Active Priorities',
        'Recent Changes',
        'Open Questions',
        'Risks / Blockers',
        'Next Actions',
        'Latest Signals',
        'Source Notes',
    ];

    private const LEGACY_MAPPING = [
        'key_concepts' => ['section' => 'Active Priorities', 'field' => 'title'],
        'active_threads' => ['section' => 'Recent Changes', 'field' => 'title'],
        'open_questions' => ['section' => 'Open Questions', 'field' => 'question'],
        'next_actions' => ['section' => 'Next Actions', 'field' => 'action'],
    ];

    public function __construct(
        private readonly WorkingMemoryScopeNormalizer $scopeNormalizer,
        private readonly WorkingMemoryContentFingerprint $fingerprint,
        private readonly WorkingMemoryDedupeFamilyResolver $familyResolver,
        private readonly WorkingMemoryVersionSuperseder $versionSuperseder,
    ) {}

    public function upsert(
        int $userId,
        string $scopeType,
        string $scopeKey,
        string $markdown,
        ?string $sourceLabel = null,
        bool $strictContentHash = false,
        ?bool $freshStart = null,
    ): WorkingMemoryUpsertResult {
        $trimmed = trim($markdown);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Working memory content must not be empty.');
        }

        if (config('working_memory.upsert_validate_sections', false)) {
            $this->assertKnownSectionsPresent($trimmed);
        }

        [$normalizedScopeType, $normalizedScopeKey] = $this->scopeNormalizer->normalize($scopeType, $scopeKey);

        $requireUuidLabels = config('working_memory.require_uuid_project_scope_key_for_source_labels', []);
        if (
            $normalizedScopeType === 'project'
            && $sourceLabel !== null
            && in_array($sourceLabel, $requireUuidLabels, true)
            && ! Str::isUuid($normalizedScopeKey)
        ) {
            throw new InvalidArgumentException(
                "Project scope_key must be a valid UUID when source_label is {$sourceLabel}."
            );
        }

        $dedupeFamily = $this->familyResolver->resolveForUpsert($normalizedScopeType, $normalizedScopeKey);

        if (! config('working_memory.dedupe_enabled', true)) {
            $version = $this->persistExternalVersion(
                $userId,
                $normalizedScopeType,
                $normalizedScopeKey,
                $trimmed,
                $sourceLabel,
                null,
                $dedupeFamily,
                null,
                null,
                $freshStart,
            );

            return new WorkingMemoryUpsertResult(
                version: $version,
                deduplicated: false,
                contentFingerprint: '',
                dedupeFamily: $dedupeFamily,
                supersededVersionId: null,
            );
        }

        $fingerprintHash = $this->fingerprint->hash($trimmed, $strictContentHash);
        $sections = $this->parseMarkdownSections($trimmed);

        return DB::transaction(function () use (
            $userId,
            $normalizedScopeType,
            $normalizedScopeKey,
            $trimmed,
            $sections,
            $sourceLabel,
            $fingerprintHash,
            $dedupeFamily,
            $freshStart,
        ): WorkingMemoryUpsertResult {
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

            $latestExternal = $memory->versions()
                ->where('build_type', 'external')
                ->whereNull('superseded_at')
                ->orderByDesc('created_at')
                ->first();

            if ($latestExternal !== null && $latestExternal->content_fingerprint === $fingerprintHash) {
                return new WorkingMemoryUpsertResult(
                    version: $latestExternal->fresh(['workingMemory']),
                    deduplicated: true,
                    contentFingerprint: $fingerprintHash,
                    dedupeFamily: $dedupeFamily,
                    supersededVersionId: null,
                );
            }

            $version = $this->persistExternalVersion(
                $userId,
                $normalizedScopeType,
                $normalizedScopeKey,
                $trimmed,
                $sourceLabel,
                $fingerprintHash,
                $dedupeFamily,
                $memory,
                $sections,
                $freshStart,
            );

            $supersededVersionId = null;
            try {
                $count = $this->versionSuperseder->supersedeAllExcept($memory, $version);
                if ($count > 0) {
                    $prior = $memory->versions()
                        ->where('build_type', 'external')
                        ->where('superseded_by_version_id', $version->id)
                        ->orderByDesc('superseded_at')
                        ->first();
                    $supersededVersionId = $prior?->id !== null ? (string) $prior->id : null;
                }
            } catch (Throwable $e) {
                RetryWorkingMemorySupersedeJob::dispatch(
                    $userId,
                    $dedupeFamily,
                    null,
                    (string) $version->id,
                );
                report($e);
            }

            return new WorkingMemoryUpsertResult(
                version: $version->fresh(['workingMemory']),
                deduplicated: false,
                contentFingerprint: $fingerprintHash,
                dedupeFamily: $dedupeFamily,
                supersededVersionId: $supersededVersionId,
            );
        });
    }

    /**
     * @param  array<string, list<string>>|null  $preparsedSections
     */
    private function persistExternalVersion(
        int $userId,
        string $normalizedScopeType,
        string $normalizedScopeKey,
        string $trimmed,
        ?string $sourceLabel,
        ?string $fingerprintHash,
        string $dedupeFamily,
        ?WorkingMemory $existingMemory = null,
        ?array $preparsedSections = null,
        ?bool $freshStart = null,
    ): WorkingMemoryVersion {
        $memory = $existingMemory ?? WorkingMemory::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'scope_type' => $normalizedScopeType,
                'scope_key' => $normalizedScopeKey,
            ],
            [
                'freshness_state' => 'stale',
            ]
        );

        $sections = $preparsedSections ?? $this->parseMarkdownSections($trimmed);
        $structuredSections = $this->buildStructuredSections($sections);
        $legacy = $this->buildLegacyPayload($structuredSections);
        $sectionReferences = $this->buildSectionReferences($structuredSections, $normalizedScopeType, $normalizedScopeKey);

        $diagnostics = array_filter([
            'source_label' => $sourceLabel,
            'dedupe_family' => $dedupeFamily,
            'fresh_start' => $freshStart === true ? true : null,
        ], fn ($v) => $v !== null && $v !== '');

        $version = $memory->versions()->create([
            'build_type' => 'external',
            'summary_markdown' => $trimmed,
            'key_concepts_json' => $legacy['key_concepts'],
            'active_threads_json' => $legacy['active_threads'],
            'open_questions_json' => $legacy['open_questions'],
            'next_actions_json' => $legacy['next_actions'],
            'structured_sections_json' => $structuredSections,
            'references_json' => [],
            'section_references_json' => $sectionReferences,
            'build_diagnostics_json' => $diagnostics !== [] ? $diagnostics : null,
            'authoring_status' => 'external',
            'content_fingerprint' => $fingerprintHash,
            'confidence_score' => 90.0,
        ]);

        $memory->forceFill([
            'latest_version_id' => $version->id,
            'freshness_state' => 'fresh',
            'last_refreshed_at' => now(),
        ])->save();

        return $version->fresh(['workingMemory']);
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseMarkdownSections(string $markdown): array
    {
        $sections = [];
        $currentSection = null;
        $currentItems = [];

        foreach (explode("\n", $markdown) as $line) {
            if (str_starts_with($line, '## ')) {
                if ($currentSection !== null) {
                    $sections[$currentSection] = $currentItems;
                }

                $heading = trim(substr($line, 3));
                $currentSection = in_array($heading, self::KNOWN_SECTIONS, true) ? $heading : null;
                $currentItems = [];

                continue;
            }

            if ($currentSection === null) {
                continue;
            }

            if (str_starts_with($line, '- ')) {
                $currentItems[] = trim(substr($line, 2));
            } elseif ($currentItems !== [] && trim($line) !== '' && ! str_starts_with($line, '#')) {
                $lastIndex = count($currentItems) - 1;
                $currentItems[$lastIndex] .= ' '.trim($line);
            }
        }

        if ($currentSection !== null) {
            $sections[$currentSection] = $currentItems;
        }

        return $sections;
    }

    /**
     * @param  array<string, list<string>>  $parsedSections
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildStructuredSections(array $parsedSections): array
    {
        $structured = [];

        foreach ($parsedSections as $sectionName => $items) {
            if ($items === []) {
                continue;
            }

            $structured[$sectionName] = array_map(
                fn (string $text): array => [
                    'id' => (string) Str::uuid(),
                    'text' => $text,
                    'importance' => 0,
                    'fallback_mode' => 'direct',
                    'citations' => [],
                ],
                $items
            );
        }

        return $structured;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $structuredSections
     * @return array<string, list<array<string, string>>>
     */
    private function buildLegacyPayload(array $structuredSections): array
    {
        $payload = [];

        foreach (self::LEGACY_MAPPING as $legacyKey => $config) {
            $sectionItems = $structuredSections[$config['section']] ?? [];
            $payload[$legacyKey] = array_map(
                fn (array $item): array => [$config['field'] => $item['text']],
                $sectionItems
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $structuredSections
     * @return array<string, list<array<string, string>>>
     */
    private function buildSectionReferences(array $structuredSections, string $scopeType, string $scopeKey): array
    {
        $refs = [];

        foreach (array_keys($structuredSections) as $sectionName) {
            $refs[$sectionName] = [
                [
                    'type' => 'stream_filter',
                    'url' => '/stream?'.http_build_query([
                        'scope_type' => $scopeType,
                        'scope_key' => $scopeKey,
                        'section' => $sectionName,
                    ], '', '&', PHP_QUERY_RFC3986),
                    'label' => $sectionName.' evidence',
                ],
            ];
        }

        return $refs;
    }

    private function assertKnownSectionsPresent(string $markdown): void
    {
        $missing = [];
        foreach (self::KNOWN_SECTIONS as $section) {
            if (! preg_match('/^##\s+'.preg_quote($section, '/').'\s*$/m', $markdown)) {
                $missing[] = $section;
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Working memory markdown is missing required sections: '.implode(', ', $missing)
            );
        }
    }
}
