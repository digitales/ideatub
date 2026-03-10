<?php

namespace App\Policies;

use App\Models\Thought;
use App\Models\User;

class ThoughtPolicy
{
    /**
     * Whether the user can view the thought (and thus see it for commenting).
     */
    public function view(User $user, Thought $thought): bool
    {
        return (int) $thought->user_id === (int) $user->id;
    }

    /**
     * Whether the user can add a comment (reply) to this thought.
     */
    public function comment(User $user, Thought $thought): bool
    {
        return (int) $thought->user_id === (int) $user->id;
    }
}
