<?php

namespace App\Policies;

use App\Models\Interaction;
use App\Models\User;

class InteractionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Interaction $interaction): bool
    {
        return $interaction->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Interaction $interaction): bool
    {
        return $interaction->user_id === $user->id;
    }

    public function delete(User $user, Interaction $interaction): bool
    {
        return $interaction->user_id === $user->id;
    }
}
