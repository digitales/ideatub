<?php

namespace App\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkingMemoryBuilderService
{
    public function __construct(
        private readonly WorkingMemoryAssembler $assembler
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
        $normalizedScopeKey = Str::of($scopeKey)->trim()->lower()->toString();
        $thoughts = $this->selectThoughts($userId, $scopeType, $normalizedScopeKey, $buildType);

        $payload = $this->assembler->assemblePayload($thoughts);
        $summaryMarkdown = $this->assembler->renderSummary($payload);

        return DB::transaction(function () use (
            $userId,
            $scopeType,
            $normalizedScopeKey,
            $buildType,
            $thoughts,
            $payload,
            $summaryMarkdown
        ): WorkingMemoryVersion {
            $memory = WorkingMemory::query()->firstOrCreate(
                [
                    'user_id' => $userId,
                    'scope_type' => $scopeType,
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
                'confidence_score' => $this->assembler->boundConfidence((float) $payload['confidence_score']),
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
                'freshness_state' => 'fresh',
                'last_refreshed_at' => now(),
            ])->save();

            return $version->fresh(['workingMemory', 'inputs']);
        });
    }

    /**
     * @return Collection<int, Thought>
     */
    private function selectThoughts(int $userId, string $scopeType, string $scopeKey, string $buildType): Collection
    {
        $thoughts = Thought::query()
            ->where('user_id', $userId)
            ->with('projects:id')
            ->orderByDesc('created_at')
            ->get();

        $scoped = $thoughts->filter(function (Thought $thought) use ($scopeType, $scopeKey): bool {
            if ($scopeType === 'global') {
                return true;
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
            return $scoped;
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
