<?php

namespace App\View\Presenters\Comments;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\ThoughtCommentRead;
use App\Models\User;
use App\Services\Comments\ResearchCommentUnreadService;
use App\Support\Comments\ShareContext;
use League\CommonMark\CommonMarkConverter;

class ResearchCommentsPresenter
{
    private ?CommonMarkConverter $converter = null;

    public function __construct(
        private readonly Thought $root,
        private readonly ?User $viewer,
        private readonly ?ShareContext $shareContext,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function pageLevelRows(): array
    {
        return $this->rowsForIds([$this->root->id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function sectionRowsFor(Thought $section): array
    {
        return $this->rowsForIds([$section->id]);
    }

    public function canCommentOnPage(): bool
    {
        return $this->root->authorizeCommentCreation($this->viewer, $this->shareContext);
    }

    public function canCommentOnSection(Thought $section): bool
    {
        return $section->authorizeCommentCreation($this->viewer, $this->shareContext);
    }

    public function unreadCount(): int
    {
        if ($this->viewer === null) {
            return 0;
        }

        $row = ThoughtCommentRead::query()
            ->where('user_id', $this->viewer->id)
            ->where('thought_id', $this->root->id)
            ->first();

        if ($row !== null) {
            return (int) $row->unread_count;
        }

        return app(ResearchCommentUnreadService::class)->recomputeCanonicalCount(
            (int) $this->viewer->id,
            (string) $this->root->id,
        );
    }

    public function allowGuestComments(): bool
    {
        return $this->shareContext !== null && $this->shareContext->allowComments;
    }

    /**
     * Props for `comments._thread` include.
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     formAction: string,
     *     commentableType: string,
     *     commentableId: string,
     *     mode: string,
     *     disabledMessage: string|null,
     *     title: string,
     *     showControls: bool
     * }
     */
    public function threadIncludeForSection(
        Thought $section,
        string $formAction,
        string $mode,
        bool $showControls,
        string $title,
        ?string $disabledMessage = null,
    ): array {
        return [
            'rows' => $this->sectionRowsFor($section),
            'formAction' => $formAction,
            'commentableType' => 'thought',
            'commentableId' => (string) $section->id,
            'mode' => $mode,
            'disabledMessage' => $disabledMessage,
            'title' => $title,
            'showControls' => $showControls,
        ];
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function rowsForIds(array $ids): array
    {
        return Comment::query()
            ->where('commentable_type', 'thought')
            ->whereIn('commentable_id', $ids)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Comment $c) => $this->row($c))
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(Comment $c): array
    {
        $isOwner = $c->author_user_id !== null
            && $c->author_user_id === $this->root->user_id;

        $contentHtml = $c->format === 'markdown'
            ? $this->converter()->convert($c->content)->getContent()
            : nl2br(e($c->content));

        $canEdit = $this->viewer !== null
            && $c->author_user_id === $this->viewer->id;

        $canDelete = $canEdit
            || ($this->viewer !== null && $this->viewer->id === $this->root->user_id);

        return [
            'id' => $c->id,
            'author_name' => $c->displayName(),
            'is_owner' => $isOwner,
            'is_guest' => $c->isGuest(),
            'content_html' => $contentHtml,
            'created_at_human' => $c->created_at->diffForHumans(),
            'updated_label' => $c->updated_at->greaterThan($c->created_at->copy()->addMinute())
                ? '(edited)'
                : null,
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
        ];
    }

    private function converter(): CommonMarkConverter
    {
        return $this->converter ??= new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
