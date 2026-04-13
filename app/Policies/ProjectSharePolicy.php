<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectShare;
use App\Models\User;

class ProjectSharePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function create(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function update(User $user, ProjectShare $projectShare): bool
    {
        return $projectShare->project->user_id === $user->id;
    }

    public function delete(User $user, ProjectShare $projectShare): bool
    {
        return $projectShare->project->user_id === $user->id;
    }
}
