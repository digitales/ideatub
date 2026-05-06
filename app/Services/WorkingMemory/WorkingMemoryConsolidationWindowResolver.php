<?php

namespace App\Services\WorkingMemory;

use App\Models\User;
use App\Models\UserPreference;

class WorkingMemoryConsolidationWindowResolver
{
    public function effectiveDaysForUserId(int $userId): int
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return $this->configuredDefault();
        }

        $raw = UserPreference::get($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS);
        if ($raw !== null && $raw !== '' && is_numeric($raw)) {
            $days = (int) $raw;

            return max(1, min(3650, $days));
        }

        return $this->configuredDefault();
    }

    public function configuredDefault(): int
    {
        return max(1, (int) config('working_memory.consolidation_window_days', 180));
    }
}
