<?php

namespace App\Policies;

use App\Models\CommitmentItem;
use App\Models\User;

class CommitmentItemPolicy
{
    public function update(User $user, CommitmentItem $commitmentItem): bool
    {
        return (int) $commitmentItem->user_id === (int) $user->id;
    }
}
