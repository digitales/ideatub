<?php

namespace App\Services;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\IdeaEffectiveDateSql;
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

        $effectiveDateSql = IdeaEffectiveDateSql::expression();

        $query = Thought::query()
            ->where('user_id', $user->id)
            ->incompleteIdeas();

        if ($minAgeDays !== null && $minAgeDays !== '') {
            $cutoff = Carbon::today()->subDays((int) $minAgeDays)->toDateString();
            $query->whereRaw("({$effectiveDateSql}) <= ?", [$cutoff]);
        }

        $query->orderByRaw($effectiveDateSql.' ASC');
        $query->take(max(1, $limit));

        $thoughts = $query->get()->all();

        // Restrict to only ideas (defensive: in case DB JSON query includes edge cases).
        return array_values(array_filter($thoughts, function (Thought $thought): bool {
            return ($thought->metadata['type'] ?? null) === 'idea';
        }));
    }

    public function countForUser(User $user): int
    {
        return count($this->forUser($user));
    }
}
