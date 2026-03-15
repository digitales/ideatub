<?php

namespace App\Policies;

use App\Models\Thought;
use App\Models\User;

class ThoughtPolicy
{
    /**
     * Whether the user can view the thought (e.g. see it in search/recent and thus comment on it).
     */
    public function view(User $user, Thought $thought): bool
    {
        return $thought->user_id === $user->id;
    }

    /**
     * Whether the user can add a comment to this thought. Only the owner can comment.
     */
    public function comment(User $user, Thought $thought): bool
    {
        return $thought->user_id === $user->id;
    }

    /**
     * Whether the user can update the thought (e.g. toggle idea completed).
     */
    public function update(User $user, Thought $thought): bool
    {
        return $thought->user_id === $user->id;
    }
}
