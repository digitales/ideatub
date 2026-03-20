<?php

namespace App\Services\Inbox\Generators;

use App\Models\Thought;
use App\Models\User;
use App\Services\Inbox\Contracts\InboxGenerator;
use App\Support\IdeaEffectiveDateSql;
use Illuminate\Support\Carbon;

class NeglectedIdeaInboxGenerator implements InboxGenerator
{
    private const NEGLECT_THRESHOLD_DAYS = 30;

    private const RESULT_LIMIT = 2;

    public function generate(User $user): array
    {
        $cutoff = Carbon::today()->subDays(self::NEGLECT_THRESHOLD_DAYS)->toDateString();
        $effectiveDateSql = IdeaEffectiveDateSql::expression();

        $ideas = Thought::query()
            ->where('user_id', $user->id)
            ->ideas()
            ->where(function ($query): void {
                $query->whereNull('metadata->completed')
                    ->orWhere('metadata->completed', '!=', true);
            })
            ->whereRaw("({$effectiveDateSql}) <= ?", [$cutoff])
            ->orderByRaw($effectiveDateSql.' ASC')
            ->limit(self::RESULT_LIMIT)
            ->get();

        return $ideas->map(function (Thought $idea): array {
            return [
                'generator_type' => 'neglected_idea',
                'title' => 'Neglected idea',
                'body' => "This idea has been sitting for a while:\n".$idea->content,
                'dedupe_key' => 'neglected_idea:'.$idea->id,
                'generated_at' => now(),
                'source_data' => [
                    'idea_id' => $idea->id,
                    'logged_date' => $idea->metadata['logged_date'] ?? null,
                ],
            ];
        })->all();
    }
}
