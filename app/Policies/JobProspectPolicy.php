<?php

namespace App\Policies;

use App\Models\JobProspect;
use App\Models\User;

class JobProspectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JobProspect $jobProspect): bool
    {
        return $jobProspect->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JobProspect $jobProspect): bool
    {
        return $jobProspect->user_id === $user->id;
    }

    public function delete(User $user, JobProspect $jobProspect): bool
    {
        return $jobProspect->user_id === $user->id;
    }
}
