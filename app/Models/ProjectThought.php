<?php

namespace App\Models;

use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Jobs\WorkingMemoryRebuildJob;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProjectThought extends Pivot
{
    /**
     * @var string
     */
    protected $table = 'project_thought';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::created(static function (ProjectThought $pivot): void {
            self::dispatchRefreshForThoughtId((string) $pivot->thought_id);
            WorkingMemoryRebuildJob::dispatch((string) $pivot->project_id);
        });

        static::deleted(static function (ProjectThought $pivot): void {
            self::dispatchRefreshForThoughtId((string) $pivot->thought_id);
            WorkingMemoryRebuildJob::dispatch((string) $pivot->project_id);
        });

        static::updated(static function (ProjectThought $pivot): void {
            $keys = array_keys($pivot->getChanges());
            $meaningful = array_values(array_diff($keys, ['updated_at', 'sort_order']));
            if ($meaningful === []) {
                return;
            }

            self::dispatchRefreshForThoughtId((string) $pivot->thought_id);
        });
    }

    private static function dispatchRefreshForThoughtId(string $thoughtId): void
    {
        $thought = Thought::query()->find($thoughtId);
        if ($thought === null || $thought->user_id === null) {
            return;
        }

        RefreshWorkingMemoryIncremental::dispatch($thoughtId);
    }
}
