<?php

namespace App\Policies;

use App\Models\LearningProject;
use App\Models\User;

class LearningProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LearningProject $learningProject): bool
    {
        return $learningProject->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, LearningProject $learningProject): bool
    {
        return $learningProject->user_id === $user->id;
    }

    public function delete(User $user, LearningProject $learningProject): bool
    {
        return $learningProject->user_id === $user->id;
    }
}
