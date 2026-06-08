<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\Thought;
use App\Services\ProjectMembershipService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProjectContextService
{
    public function __construct(
        private readonly ProjectMembershipService $membership,
    ) {}

    public function pin(Project $project, Thought $thought): void
    {
        if ($project->user_id !== $thought->user_id) {
            throw new InvalidArgumentException('Thought must belong to the project owner.');
        }

        DB::transaction(function () use ($project, $thought): void {
            if (! $project->thoughts()->whereKey($thought->id)->exists()) {
                $this->membership->addThought($project, $thought);
            }

            $project->update(['context_thought_id' => $thought->id]);
        });
    }

    public function unpin(Project $project): void
    {
        if ($project->context_thought_id === null) {
            return;
        }

        $project->update(['context_thought_id' => null]);
    }

    public function clearIfPinned(Project $project, Thought $thought): void
    {
        if ((string) $project->context_thought_id !== (string) $thought->id) {
            return;
        }

        $project->update(['context_thought_id' => null]);
    }
}
