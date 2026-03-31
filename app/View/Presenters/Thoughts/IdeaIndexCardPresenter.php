<?php

namespace App\View\Presenters\Thoughts;

use App\Models\Thought;
use App\View\Presenters\Concerns\EnsuresPresenterDataIsLoaded;
use App\View\Presenters\Email\NewsletterResearchStatusPresenter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Per-card view state for the idea index feed (recent + search).
 */
final class IdeaIndexCardPresenter
{
    use EnsuresPresenterDataIsLoaded;

    private function __construct(
        private readonly Thought $thought,
        private readonly int $currentReplyableIndex,
        private readonly string $replyHref,
        private readonly ?string $parentPreviewExcerpt,
        private readonly string $createdAtHuman,
        private readonly bool $editable,
        private readonly bool $previewMode,
        private readonly ?NewsletterResearchStatusPresenter $newsletterResearchStatus,
    ) {
        $this->requireRelationLoaded($this->thought, 'comments');
        if ($this->thought->parent_id !== null) {
            $this->requireRelationLoaded($this->thought, 'parent');
        }
    }

    public static function fromThought(
        Thought $thought,
        int $currentReplyableIndex,
        ?NewsletterResearchStatusPresenter $newsletterResearchStatus = null,
    ): self {
        $replyHref = $thought->parent_id ? '' : route('idea.index', ['parent_id' => $thought->id]);
        $parentPreview = null;
        if ($thought->parent_id && $thought->relationLoaded('parent') && $thought->parent) {
            $parentPreview = Str::limit($thought->parent->content, 80);
        }

        $userId = Auth::id();
        $editable = Auth::check() && $userId !== null && (int) $userId === (int) $thought->user_id;

        return new self(
            $thought,
            $currentReplyableIndex,
            $replyHref,
            $parentPreview,
            $thought->created_at->diffForHumans(),
            $editable,
            $thought->parent_id === null,
            $newsletterResearchStatus,
        );
    }

    public function thought(): Thought
    {
        return $this->thought;
    }

    public function currentReplyableIndex(): int
    {
        return $this->currentReplyableIndex;
    }

    public function replyHref(): string
    {
        return $this->replyHref;
    }

    public function parentPreviewExcerpt(): ?string
    {
        return $this->parentPreviewExcerpt;
    }

    public function showParentPreview(): bool
    {
        return $this->parentPreviewExcerpt !== null;
    }

    public function createdAtHuman(): string
    {
        return $this->createdAtHuman;
    }

    public function editable(): bool
    {
        return $this->editable;
    }

    public function previewMode(): bool
    {
        return $this->previewMode;
    }

    public function newsletterResearchStatus(): ?NewsletterResearchStatusPresenter
    {
        return $this->newsletterResearchStatus;
    }

    public function showReplyLink(): bool
    {
        return ! $this->thought->parent_id;
    }
}
