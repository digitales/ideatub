<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function update(User $user, Comment $comment): bool
    {
        return $comment->author_user_id === $user->id;
    }

    public function delete(User $user, Comment $comment): bool
    {
        if ($comment->author_user_id === $user->id) {
            return true;
        }

        $commentable = $comment->commentable;

        if ($commentable === null) {
            return false;
        }

        $ownerId = method_exists($commentable, 'commentableOwnerId')
            ? $commentable->commentableOwnerId()
            : null;

        return $ownerId !== null && $ownerId === $user->id;
    }
}
