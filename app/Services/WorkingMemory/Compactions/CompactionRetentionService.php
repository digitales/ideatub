<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;

class CompactionRetentionService
{
    public function trim(WorkingMemory $memory, string $buildType): void
    {
        if (! str_starts_with($buildType, 'compaction:')) {
            return;
        }

        $subtype = substr($buildType, strlen('compaction:'));
        $caps = config('working_memory.compaction_retention', []);
        $cap = is_array($caps) && isset($caps[$subtype]) && is_int($caps[$subtype]) ? $caps[$subtype] : null;

        if ($cap === null || $cap <= 0) {
            return;
        }

        $count = WorkingMemoryVersion::query()
            ->where('working_memory_id', $memory->id)
            ->where('build_type', $buildType)
            ->count();

        if ($count <= $cap) {
            return;
        }

        $excess = $count - $cap;
        WorkingMemoryVersion::query()
            ->where('working_memory_id', $memory->id)
            ->where('build_type', $buildType)
            ->orderBy('created_at')
            ->limit($excess)
            ->get()
            ->each(fn (WorkingMemoryVersion $v) => $v->delete());
    }
}
