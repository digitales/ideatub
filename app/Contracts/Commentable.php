<?php

namespace App\Contracts;

use App\Models\User;
use App\Support\Comments\ShareContext;

interface Commentable
{
    public function commentableOwnerId(): ?int;

    public function authorizeCommentCreation(?User $user, ?ShareContext $shareContext): bool;
}
