<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Models\ThoughtLink;
use App\Models\ThoughtSuggestedLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ComputeSemanticLinkSuggestionsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $thoughtId,
    ) {}

    public function handle(): void
    {
        if (! config('features.memory_graph_suggestions')) {
            return;
        }

        $focal = Thought::query()->find($this->thoughtId);
        if ($focal === null || $focal->user_id === null || $focal->embedding === null) {
            return;
        }

        $userId = (int) $focal->user_id;
        $maxDistance = 0.45;
        $limit = 5;

        $vector = is_array($focal->embedding)
            ? json_encode($focal->embedding)
            : (string) $focal->embedding;

        $neighbors = Thought::query()
            ->selectRaw('thoughts.*, (embedding <=> ?::vector) as neighbor_distance', [$vector])
            ->where('user_id', $userId)
            ->where('id', '!=', $focal->id)
            ->visibleInStream()
            ->whereNotNull('embedding')
            ->nearestWithin($focal->embedding, $maxDistance)
            ->limit($limit)
            ->get();

        $existingLinkTargets = ThoughtLink::query()
            ->where('user_id', $userId)
            ->where(function ($q) use ($focal) {
                $q->where('from_thought_id', $focal->id)
                    ->orWhere('to_thought_id', $focal->id);
            })
            ->get()
            ->flatMap(fn (ThoughtLink $link) => [$link->from_thought_id, $link->to_thought_id])
            ->unique()
            ->flip();

        $keptTargetIds = [];

        foreach ($neighbors as $neighbor) {
            if (isset($existingLinkTargets[$neighbor->id])) {
                continue;
            }

            ThoughtSuggestedLink::query()->updateOrCreate(
                [
                    'from_thought_id' => $focal->id,
                    'to_thought_id' => $neighbor->id,
                ],
                [
                    'user_id' => $userId,
                    'distance' => (float) ($neighbor->neighbor_distance ?? $maxDistance),
                    'computed_at' => now(),
                    'dismissed_at' => null,
                    'promoted_at' => null,
                ],
            );

            $keptTargetIds[] = $neighbor->id;
        }

        ThoughtSuggestedLink::query()
            ->where('from_thought_id', $focal->id)
            ->whereNull('dismissed_at')
            ->whereNull('promoted_at')
            ->when(
                $keptTargetIds !== [],
                fn ($q) => $q->whereNotIn('to_thought_id', $keptTargetIds)
            )
            ->delete();
    }
}
