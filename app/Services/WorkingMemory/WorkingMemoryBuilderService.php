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
        try {
            if ($normalizedScopeType === 'insights') {
                $synthesis = $this->memoryInsightsService->synthesizePersistable($thoughts);
                $summaryMarkdown = $synthesis['summary_markdown'];
                $payload = [
                    'key_concepts' => $synthesis['key_concepts'],
                    'active_threads' => $synthesis['active_threads'],
                    'open_questions' => $synthesis['open_questions'],
                    'next_actions' => $synthesis['next_actions'],
                    'confidence_score' => $synthesis['confidence_score'],
                ];
            } else {
                $payload = $this->assembler->assemblePayload($thoughts);
                $summaryMarkdown = $this->assembler->renderSummary($payload);
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
            $summaryMarkdown
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
