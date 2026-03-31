<?php

namespace App\View\Presenters\Thoughts;

use App\Models\Thought;
use App\Services\DemoMode;
use App\View\Presenters\Concerns\EnsuresPresenterDataIsLoaded;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use App\View\Presenters\Email\NewsletterResearchStatusPresenter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Per-card view state for the idea index feed (recent + search).
 */
final class IdeaIndexCardPresenter
{
    use EnsuresPresenterDataIsLoaded;
    use ObfuscatesDemoText;

    private function __construct(
        private readonly Thought $thought,
        private readonly int $currentReplyableIndex,
        private readonly string $replyHref,
        private readonly string $createdAtHuman,
        private readonly bool $ownerMayInlineEdit,
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

        $userId = Auth::id();
        $ownerMayInlineEdit = Auth::check() && $userId !== null && (int) $userId === (int) $thought->user_id;

        return new self(
            $thought,
            $currentReplyableIndex,
            $replyHref,
            $thought->created_at->diffForHumans(),
            $ownerMayInlineEdit,
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

    public function displayParentPreviewExcerpt(): ?string
    {
        if ($this->thought->parent_id === null || ! $this->thought->relationLoaded('parent') || ! $this->thought->parent) {
            return null;
        }

        $raw = Str::limit($this->thought->parent->content, 80);

        return $this->obfuscatedOrRaw($raw, 'idea_index_parent_preview', 'idea_index_card_presenter.parent_preview');
    }

    public function showParentPreview(): bool
    {
        return $this->thought->parent_id !== null
            && $this->thought->relationLoaded('parent')
            && $this->thought->parent !== null;
    }

    public function displayContent(): string
    {
        $raw = (string) ($this->thought->content ?? '');

        return $this->obfuscatedOrRaw($raw, 'idea_index_card_body', 'idea_index_card_presenter.display_content');
    }

    /**
     * @return list<array{content: string, created_at_human: string}>
     */
    public function commentPreviewRows(): array
    {
        return $this->thought->comments
            ->map(function (Thought $comment): array {
                $raw = Str::limit($comment->content, 200);
                $content = $this->obfuscatedOrRaw($raw, 'idea_index_comment_preview', 'idea_index_card_presenter.comment_preview');

                return [
                    'content' => $content,
                    'created_at_human' => $comment->created_at->diffForHumans(),
                ];
            })
            ->all();
    }

    public function createdAtHuman(): string
    {
        return $this->createdAtHuman;
    }

    public function editable(): bool
    {
        if (app(DemoMode::class)->enabled()) {
            return false;
        }

        return $this->ownerMayInlineEdit;
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

    private function obfuscatedOrRaw(string $value, string $context, string $boundary): string
    {
        try {
            return $this->demoText($value, $context) ?? '';
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for idea index card presenter field.', [
                'boundary' => $boundary,
                'context' => $context,
                'thought_id' => $this->thought->id,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }
}
