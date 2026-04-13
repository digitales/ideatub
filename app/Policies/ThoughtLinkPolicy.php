<?php

namespace App\Policies;

use App\Models\ThoughtLink;
use App\Models\User;

class ThoughtLinkPolicy
{
    public function delete(User $user, ThoughtLink $thoughtLink): bool
    {
        return $thoughtLink->user_id === $user->id;
    }
}
