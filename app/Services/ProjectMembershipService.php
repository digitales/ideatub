<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Thought;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProjectMembershipService
{
    public function addThought(Project $project, Thought $thought): void
    {
        if ($project->user_id !== $thought->user_id) {
            throw new InvalidArgumentException('Thought must belong to the project owner.');
        }

        if ($project->thoughts()->whereKey($thought->id)->exists()) {
            return;
        }

        $max = DB::table('project_thought')
            ->where('project_id', $project->id)
            ->max('sort_order');

        $next = $max === null ? 0 : (int) $max + 1;

        $project->thoughts()->attach($thought->id, ['sort_order' => $next]);
    }

    public function removeThought(Project $project, Thought $thought): void
    {
        if ((string) $project->context_thought_id === (string) $thought->id) {
            $project->update(['context_thought_id' => null]);
        }

        $project->thoughts()->detach($thought->id);
        $this->normalizeSortOrder($project);
    }

    /**
     * @param  list<string>  $orderedThoughtIds
     */
    public function reorder(Project $project, array $orderedThoughtIds): void
    {
        $current = $project->thoughts()->pluck('thoughts.id')->sort()->values()->all();
        $incoming = collect($orderedThoughtIds)->sort()->values()->all();

        if ($current !== $incoming) {
            throw new InvalidArgumentException('Ordered ids must match current project members.');
        }

        DB::transaction(function () use ($project, $orderedThoughtIds): void {
            foreach (array_values($orderedThoughtIds) as $index => $thoughtId) {
                $project->thoughts()->updateExistingPivot($thoughtId, ['sort_order' => 1_000_000 + $index]);
            }
            foreach (array_values($orderedThoughtIds) as $index => $thoughtId) {
                $project->thoughts()->updateExistingPivot($thoughtId, ['sort_order' => $index]);
            }
        });
    }

    private function normalizeSortOrder(Project $project): void
    {
        $ids = $project->thoughts()->orderByPivot('sort_order')->pluck('thoughts.id')->all();

        DB::transaction(function () use ($project, $ids): void {
            foreach (array_values($ids) as $index => $thoughtId) {
                $project->thoughts()->updateExistingPivot($thoughtId, ['sort_order' => 1_000_000 + $index]);
            }
            foreach (array_values($ids) as $index => $thoughtId) {
                $project->thoughts()->updateExistingPivot($thoughtId, ['sort_order' => $index]);
            }
        });
    }
}
