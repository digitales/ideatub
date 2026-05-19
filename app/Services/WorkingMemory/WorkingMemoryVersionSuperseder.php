<?php

namespace App\Services\WorkingMemory;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;

class WorkingMemoryVersionSuperseder
{
    public function supersedeAllExcept(WorkingMemory $memory, WorkingMemoryVersion $winner): int
    {
        return $memory->versions()
            ->where('build_type', 'external')
            ->whereNull('superseded_at')
            ->whereKeyNot($winner->id)
            ->update([
                'superseded_at' => now(),
                'superseded_by_version_id' => $winner->id,
            ]);
    }
}
