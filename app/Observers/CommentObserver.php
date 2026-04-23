<?php

namespace App\Observers;

use App\Models\Comment;
use App\Services\Comments\ResearchCommentUnreadService;

class CommentObserver
{
    public function __construct(
        private readonly ResearchCommentUnreadService $researchCommentUnreadService,
    ) {}

    public function created(Comment $comment): void
    {
        $this->researchCommentUnreadService->handleCommentCreated($comment);
    }

    public function deleting(Comment $comment): void
    {
        $this->researchCommentUnreadService->handleCommentDeleting($comment);
    }
}
