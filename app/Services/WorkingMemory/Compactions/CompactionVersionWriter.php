<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryOutputValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CompactionVersionWriter
{
    /**
     * Required sections for each compaction subtype, mirroring the prompt-builder contracts.
     *
     * @var array<string, array<int, string>>
     */
    private const REQUIRED_SECTIONS = [
        'compaction:meeting' => [
            'Summary',
            'Decisions',
            'Action Items',
            'Risks / Blockers',
            'Open Questions',
        ],
        'compaction:weekly-digest' => [
            'Latest Signals',
            'Active Priorities',
            'Recent Changes',
        ],
        'compaction:topic-digest' => [
            'Active Priorities',
            'Open Questions',
            'Latest Signals',
        ],
        'compaction:research-synth' => [
            'Open Questions',
            'Risks / Blockers',
            'Latest Signals',
            'Source Notes',
        ],
    ];

    public function __construct(
        private readonly CompactionRetentionService $retention,
        private readonly WorkingMemoryOutputValidator $validator,
    ) {}

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $structuredSections
     * @param  array<int, array{type: string, url: string, label: string}>  $references
     * @param  array<int, string>  $sourceThoughtIds
     */
    public function write(
        int $userId,
        string $scopeType,
        string $scopeKey,
        string $buildType,
        string $summaryMarkdown,
        array $structuredSections,
        array $references,
        array $sourceThoughtIds,
    ): ?WorkingMemoryVersion {
        if (! str_starts_with($buildType, 'compaction:')) {
            throw new InvalidArgumentException(
                "CompactionVersionWriter only accepts compaction:* build types, got: {$buildType}"
            );
        }

        $payload = [
            'summary_markdown' => $summaryMarkdown,
            'structured_sections' => $structuredSections,
            'references' => $references,
        ];

        if ($this->shouldAbortPersistence($userId, $scopeType, $scopeKey, $buildType, $payload)) {
            return null;
        }

        return DB::transaction(function () use (
            $userId,
            $scopeType,
            $scopeKey,
            $buildType,
            $summaryMarkdown,
            $structuredSections,
            $references,
            $sourceThoughtIds,
        ): WorkingMemoryVersion {
            $memory = WorkingMemory::query()->firstOrCreate(
                [
                    'user_id' => $userId,
                    'scope_type' => $scopeType,
                    'scope_key' => $scopeKey,
                ],
                [
                    'freshness_state' => 'stale',
                ]
            );

            $version = $memory->versions()->create([
                'build_type' => $buildType,
                'summary_markdown' => $summaryMarkdown,
                'structured_sections_json' => $structuredSections,
                'references_json' => $references,
                'authoring_status' => 'validated',
                'confidence_score' => 0,
            ]);

            foreach (array_unique($sourceThoughtIds) as $thoughtId) {
                $version->inputs()->create([
                    'thought_id' => $thoughtId,
                    'source_version_id' => null,
                    'contribution_type' => 'compaction-source',
                    'weight' => 1.0,
                ]);
            }

            $this->retention->trim($memory, $buildType);

            return $version;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldAbortPersistence(int $userId, string $scopeType, string $scopeKey, string $buildType, array $payload): bool
    {
        $required = self::REQUIRED_SECTIONS[$buildType] ?? null;
        if ($required === null) {
            return false;
        }

        $result = $this->validator->validate(
            payload: $payload,
            minimumCoverage: null,
            compactionCountInScope: 0,
            requiredSections: $required,
        );

        if (($result['failure_type'] ?? null) !== 'hard') {
            return false;
        }

        $enforced = (bool) config('working_memory.compaction_validation_enforced', false);

        Log::warning('CompactionVersionWriter validation hard-failed', [
            'user_id' => $userId,
            'build_type' => $buildType,
            'scope_type' => $scopeType,
            'scope_key' => $scopeKey,
            'enforced' => $enforced,
            'message' => $result['message'] ?? null,
            'reason_codes' => $result['diagnostics']['reason_codes'] ?? [],
            'diagnostics' => $result['diagnostics'] ?? [],
        ]);

        return $enforced;
    }
}
