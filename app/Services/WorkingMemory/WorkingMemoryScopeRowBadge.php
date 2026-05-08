<?php

namespace App\Services\WorkingMemory;

use App\Models\WorkingMemory;

final class WorkingMemoryScopeRowBadge
{
    public static function label(WorkingMemory $memory): ?string
    {
        if ($memory->build_started_at !== null) {
            return 'Updating';
        }

        $status = $memory->latestVersion?->authoring_status;

        if ($status === 'fallback') {
            return 'Fallback';
        }

        return null;
    }
}
