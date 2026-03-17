<?php

namespace App\Policies;

use App\Models\ResearchShare;
use App\Models\User;

class ResearchSharePolicy
{
    /**
     * Whether the user can view the share (e.g. see it in their list).
     */
    public function view(User $user, ResearchShare $researchShare): bool
    {
        return $researchShare->user_id === $user->id;
    }

    /**
     * Whether the user can update the share (e.g. set password or expiry).
     */
    public function update(User $user, ResearchShare $researchShare): bool
    {
        return $researchShare->user_id === $user->id;
    }

    /**
     * Whether the user can delete (revoke) the share.
     */
    public function delete(User $user, ResearchShare $researchShare): bool
    {
        return $researchShare->user_id === $user->id;
    }
}
