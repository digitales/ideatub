<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserMcpKey;

class UserMcpKeyPolicy
{
    /**
     * Whether the user can view their own MCP keys (list / settings page).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Whether the user can view this specific key. Only the owner can.
     */
    public function view(User $user, UserMcpKey $userMcpKey): bool
    {
        return $userMcpKey->user_id === $user->id;
    }

    /**
     * Whether the user can create a new MCP key for themselves.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Whether the user can update this key's label. Only the owner can.
     */
    public function update(User $user, UserMcpKey $userMcpKey): bool
    {
        return $userMcpKey->user_id === $user->id;
    }

    /**
     * Whether the user can delete (revoke) this key. Only the owner can.
     */
    public function delete(User $user, UserMcpKey $userMcpKey): bool
    {
        return $userMcpKey->user_id === $user->id;
    }
}
