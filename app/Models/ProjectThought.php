<?php

namespace App\Models;

use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Jobs\WorkingMemoryRebuildJob;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

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
            self::dispatchSideEffectsAfterCommit(
                (string) $pivot->thought_id,
                (string) $pivot->project_id,
            );
        });

        static::deleted(static function (ProjectThought $pivot): void {
            self::dispatchSideEffectsAfterCommit(
                (string) $pivot->thought_id,
                (string) $pivot->project_id,
            );
        });

        static::updated(static function (ProjectThought $pivot): void {
            $keys = array_keys($pivot->getChanges());
            $meaningful = array_values(array_diff($keys, ['updated_at', 'sort_order']));
            if ($meaningful === []) {
                return;
            }

            $thoughtId = (string) $pivot->thought_id;
            DB::afterCommit(static function () use ($thoughtId): void {
                self::dispatchRefreshForThoughtId($thoughtId);
            });
        });
    }

    /**
     * Defer queue side effects until the membership transaction commits.
     *
     * WorkingMemoryRebuildJob is ShouldBeUnique and uses the database cache lock store.
     * Acquiring that lock inside an open Postgres transaction can hit a unique conflict,
     * abort the transaction (SQLSTATE 25P02), and roll back the project_thought row.
     */
    private static function dispatchSideEffectsAfterCommit(string $thoughtId, string $projectId): void
    {
        DB::afterCommit(static function () use ($thoughtId, $projectId): void {
            self::dispatchRefreshForThoughtId($thoughtId);
            WorkingMemoryRebuildJob::dispatch($projectId);
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
