<?php

namespace App\Policies;

use App\Models\InboxItem;
use App\Models\User;

class InboxItemPolicy
{
    public function view(User $user, InboxItem $inboxItem): bool
    {
        return $inboxItem->user_id === $user->id;
    }

    public function update(User $user, InboxItem $inboxItem): bool
    {
        return $inboxItem->user_id === $user->id;
    }
}
