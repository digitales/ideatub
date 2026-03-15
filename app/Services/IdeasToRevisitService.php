<?php

namespace App\Services;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Carbon;

/**
 * Returns a list of incomplete ideas for a user to revisit, ordered by age (oldest first),
 * limited and optionally filtered by user preferences.
 */
class IdeasToRevisitService
{
    /**
     * Ideas to revisit for the given user: incomplete ideas only, ordered by age (oldest first),
     * limited by ideas_to_revisit_limit (default 15), optionally filtered by min age in days.
     *
     * @return array<int, Thought>
     */
    public function forUser(User $user): array
    {
        $limit = (int) UserPreference::get($user, 'ideas_to_revisit_limit', 15);
        $minAgeDays = UserPreference::get($user, 'ideas_to_revisit_min_age_days');

        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        $effectiveDateSql = $driver === 'pgsql'
            ? "COALESCE((metadata->>'logged_date')::date, created_at::date)"
            : "COALESCE(json_extract(metadata, '$.logged_date'), date(created_at))";

        $query = Thought::query()
            ->where('user_id', $user->id)
            ->ideas()
            ->where(function ($q): void {
                $q->whereNull('metadata->completed')
                    ->orWhere('metadata->completed', '!=', true);
            });

        if ($minAgeDays !== null && $minAgeDays !== '') {
            $cutoff = Carbon::today()->subDays((int) $minAgeDays)->toDateString();
            $query->whereRaw("({$effectiveDateSql}) <= ?", [$cutoff]);
        }

        $query->orderByRaw($effectiveDateSql . ' ASC');
        $query->take(max(1, $limit));

        $thoughts = $query->get()->all();

        // Restrict to only ideas (defensive: in case DB JSON query includes edge cases).
        return array_values(array_filter($thoughts, function (Thought $thought): bool {
            return ($thought->metadata['type'] ?? null) === 'idea';
        }));
    }
}
