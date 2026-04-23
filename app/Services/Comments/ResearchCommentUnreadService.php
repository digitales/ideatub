<?php

namespace App\Services\Comments;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\ThoughtCommentRead;
use App\Support\Comments\ResearchUnreadResearchRootResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class ResearchCommentUnreadService
{
    /**
     * Unix epoch: "never read" placeholder when a row is created only from comment activity.
     */
    private static function neverReadAt(): Carbon
    {
        return Carbon::createFromTimestampUTC(0);
    }

    public function handleCommentCreated(Comment $comment): void
    {
        $this->applyDelta($comment, +1);
    }

    public function handleCommentDeleting(Comment $comment): void
    {
        $this->applyDelta($comment, -1);
    }

    private function applyDelta(Comment $comment, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        if ($comment->commentable_type !== 'thought') {
            return;
        }

        /** @var Thought|null $commentable */
        $commentable = Thought::query()->find($comment->commentable_id);
        if ($commentable === null) {
            return;
        }

        $researchRoot = ResearchUnreadResearchRootResolver::researchRootForThought($commentable);
        if ($researchRoot === null) {
            return;
        }

        if (! ResearchUnreadResearchRootResolver::commentableIsInResearchUnreadTree($researchRoot, $commentable)) {
            return;
        }

        $ownerId = (int) $researchRoot->user_id;
        if ($comment->author_user_id !== null && (int) $comment->author_user_id === $ownerId) {
            return;
        }

        if (! $this->commentWouldAffectUnreadCount($ownerId, $researchRoot->id, $comment)) {
            return;
        }

        DB::transaction(function () use ($ownerId, $researchRoot, $delta): void {
            $row = ThoughtCommentRead::query()
                ->where('user_id', $ownerId)
                ->where('thought_id', $researchRoot->id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                if ($delta < 0) {
                    return;
                }
                ThoughtCommentRead::query()->insert([
                    'user_id' => $ownerId,
                    'thought_id' => $researchRoot->id,
                    'last_read_at' => self::neverReadAt(),
                    'unread_count' => max(0, $delta),
                ]);

                return;
            }

            $next = max(0, (int) $row->unread_count + $delta);
            $row->unread_count = $next;
            $row->save();
        });
    }

    /**
     * Same rules as {@see ResearchCommentsPresenter::unreadCount()} for whether a comment row is included.
     */
    public function commentWouldAffectUnreadCount(int $ownerUserId, string $researchRootId, Comment $comment): bool
    {
        $row = ThoughtCommentRead::query()
            ->where('user_id', $ownerUserId)
            ->where('thought_id', $researchRootId)
            ->first();

        if ($row === null) {
            return true;
        }

        $lastRead = $row->last_read_at;
        if ($lastRead === null || $this->isNeverReadPlaceholder($lastRead)) {
            return true;
        }

        return $comment->created_at->greaterThan($lastRead);
    }

    private function isNeverReadPlaceholder(Carbon $lastRead): bool
    {
        return $lastRead->timestamp === 0;
    }

    /**
     * Canonical unread count for (owner as viewer, research root) — matches presenter SQL.
     */
    public function recomputeCanonicalCount(int $viewerUserId, string $researchRootId): int
    {
        $lastRead = ThoughtCommentRead::query()
            ->where('user_id', $viewerUserId)
            ->where('thought_id', $researchRootId)
            ->value('last_read_at');

        $ids = collect([$researchRootId])
            ->merge(
                Thought::query()
                    ->where('parent_id', $researchRootId)
                    ->pluck('id')
            )
            ->unique()
            ->values();

        $q = Comment::query()
            ->whereIn('commentable_id', $ids)
            ->where('commentable_type', 'thought')
            ->where(function ($q) use ($viewerUserId) {
                $q->whereNull('author_user_id')
                    ->orWhere('author_user_id', '<>', $viewerUserId);
            });

        if ($lastRead !== null && ! $this->isNeverReadPlaceholder($lastRead)) {
            $q->where('created_at', '>', $lastRead);
        }

        return (int) $q->count();
    }

    /**
     * @return int Number of rows updated
     */
    public function reconcileStoredCounts(): int
    {
        $updated = 0;

        $researchRootIds = Thought::query()
            ->whereNull('parent_id')
            ->matchingCanonicalMetadataType('research')
            ->pluck('id');

        $pairs = ThoughtCommentRead::query()
            ->whereIn('thought_id', $researchRootIds)
            ->get();

        foreach ($pairs as $row) {
            $canonical = $this->recomputeCanonicalCount((int) $row->user_id, (string) $row->thought_id);
            if ((int) $row->unread_count !== $canonical) {
                ThoughtCommentRead::query()
                    ->where('user_id', $row->user_id)
                    ->where('thought_id', $row->thought_id)
                    ->update(['unread_count' => $canonical]);
                $updated++;
            }
        }

        return $updated;
    }
}
