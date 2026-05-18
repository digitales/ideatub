<?php

namespace App\Services\WorkingMemory;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;

class WorkingMemoryExternalGuard
{
    public function __construct(
        private readonly WorkingMemoryScopeNormalizer $scopeNormalizer,
    ) {}

    public function shouldSkipConsolidatedBuild(
        int $userId,
        string $scopeType,
        string $scopeKey,
        bool $force = false,
    ): bool {
        if ($force) {
            return false;
        }

        [$normalizedType, $normalizedKey] = $this->scopeNormalizer->normalize($scopeType, $scopeKey);
        $protectDays = max(0, (int) config('working_memory.external_protect_days', 14));
        if ($protectDays === 0) {
            return false;
        }

        $memory = WorkingMemory::query()
            ->where('user_id', $userId)
            ->where('scope_type', $normalizedType)
            ->where('scope_key', $normalizedKey)
            ->first();

        if ($memory === null) {
            return false;
        }

        $external = $memory->versions()
            ->where('build_type', 'external')
            ->where('authoring_status', 'external')
            ->orderByDesc('created_at')
            ->first();

        if (! $external instanceof WorkingMemoryVersion) {
            return false;
        }

        return $external->created_at !== null
            && $external->created_at->gte(now()->subDays($protectDays));
    }
}
