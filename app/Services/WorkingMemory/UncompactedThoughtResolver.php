<?php

namespace App\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class UncompactedThoughtResolver
{
    public function __construct(
        private readonly ThoughtScopeQuery $thoughtScopeQuery,
        private readonly WorkingMemoryScopeNormalizer $scopeNormalizer,
    ) {}

    public function shouldRunIncrementalBuild(int $userId, string $scopeType, string $scopeKey): bool
    {
        if (! $this->compactionPrimaryEnabled()) {
            return true;
        }

        [$normalizedScopeType, $normalizedScopeKey] = $this->scopeNormalizer->normalize($scopeType, $scopeKey);

        $memory = $this->findMemory($userId, $normalizedScopeType, $normalizedScopeKey);

        if ($memory === null) {
            return $this->hasUncompactedThoughts($userId, $normalizedScopeType, $normalizedScopeKey, 'incremental');
        }

        if ($this->hasUncompactedThoughts($userId, $normalizedScopeType, $normalizedScopeKey, 'incremental')) {
            return true;
        }

        return $this->hasNewCompactionSinceLastIncremental($memory);
    }

    /**
     * @return Collection<int, Thought>
     */
    public function uncompactedThoughts(
        int $userId,
        string $scopeType,
        string $scopeKey,
        string $buildType,
    ): Collection {
        [$normalizedScopeType, $normalizedScopeKey] = $this->scopeNormalizer->normalize($scopeType, $scopeKey);
        $since = $this->uncompactedSince($userId, $normalizedScopeType, $normalizedScopeKey, $buildType);
        $limit = max(1, (int) config('working_memory.uncompacted_thought_limit', 20));

        return $this->thoughtScopeQuery->forScope(
            $userId,
            $normalizedScopeType,
            $normalizedScopeKey,
            $since,
            $limit,
        );
    }

    public function hasUncompactedThoughts(
        int $userId,
        string $scopeType,
        string $scopeKey,
        string $buildType = 'incremental',
    ): bool {
        return $this->uncompactedThoughts($userId, $scopeType, $scopeKey, $buildType)->isNotEmpty();
    }

    public function uncompactedSince(
        int $userId,
        string $scopeType,
        string $scopeKey,
        string $buildType,
    ): ?Carbon {
        $baseline = $this->baselineSince($userId, $scopeType, $scopeKey);

        if ($buildType !== 'consolidated') {
            return $baseline;
        }

        $windowDays = max(1, (int) config('working_memory.digest_window_days', 7));
        $windowCutoff = now()->subDays($windowDays);

        if ($baseline === null) {
            return $windowCutoff;
        }

        return $baseline->lt($windowCutoff) ? $baseline : $windowCutoff;
    }

    private function baselineSince(int $userId, string $scopeType, string $scopeKey): ?Carbon
    {
        $memory = $this->findMemory($userId, $scopeType, $scopeKey);
        if ($memory === null) {
            return null;
        }

        $timestamps = [];

        $latestCompaction = $memory->versions()
            ->where('build_type', 'like', 'compaction:%')
            ->orderByDesc('created_at')
            ->first();

        if ($latestCompaction?->created_at !== null) {
            $timestamps[] = $latestCompaction->created_at;
        }

        $canonical = $memory->versions()
            ->whereIn('build_type', ['consolidated', 'external'])
            ->whereNull('superseded_at')
            ->orderByDesc('created_at')
            ->first();

        if ($canonical?->created_at !== null) {
            $timestamps[] = $canonical->created_at;
        }

        if ($timestamps === []) {
            return null;
        }

        return collect($timestamps)->max();
    }

    private function hasNewCompactionSinceLastIncremental(WorkingMemory $memory): bool
    {
        $latestIncremental = $memory->versions()
            ->where('build_type', 'incremental')
            ->orderByDesc('created_at')
            ->first();

        $latestCompaction = $memory->versions()
            ->where('build_type', 'like', 'compaction:%')
            ->orderByDesc('created_at')
            ->first();

        if (! $latestCompaction instanceof WorkingMemoryVersion) {
            return false;
        }

        if (! $latestIncremental instanceof WorkingMemoryVersion) {
            return true;
        }

        if ($latestCompaction->created_at === null || $latestIncremental->created_at === null) {
            return false;
        }

        return $latestCompaction->created_at->gt($latestIncremental->created_at);
    }

    private function findMemory(int $userId, string $scopeType, string $scopeKey): ?WorkingMemory
    {
        return WorkingMemory::query()
            ->where('user_id', $userId)
            ->where('scope_type', $scopeType)
            ->where('scope_key', $scopeKey)
            ->first();
    }

    private function compactionPrimaryEnabled(): bool
    {
        return filter_var(config('working_memory.compaction_primary', true), FILTER_VALIDATE_BOOL);
    }
}
