<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserInboundAddress;

class UserInboundAddressPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserInboundAddress $userInboundAddress): bool
    {
        return $userInboundAddress->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, UserInboundAddress $userInboundAddress): bool
    {
        return $userInboundAddress->user_id === $user->id;
    }
}
