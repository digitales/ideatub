<?php

namespace App\View\Presenters\Thoughts;

use App\Models\Thought;
use App\Services\DemoMode;
use App\Services\Video\VideoCaptureService;
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
        private readonly ?string $videoLatestResearchUrl,
    ) {
        $this->requireRelationLoaded($this->thought, 'childThoughts');
        if ($this->thought->parent_id !== null) {
            $this->requireRelationLoaded($this->thought, 'parent');
        }
    }

    public static function fromThought(
        Thought $thought,
        int $currentReplyableIndex,
        ?NewsletterResearchStatusPresenter $newsletterResearchStatus = null,
        ?string $videoLatestResearchUrl = null,
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
            $videoLatestResearchUrl,
        );
    }

    public function thought(): Thought
    {
        return $this->thought;
    }

    public function documentShareEligible(): bool
    {
        return $this->thought->isShareableDocumentRoot();
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

        return $this->obfuscatedOrRaw($raw, 'thought_parent_preview', 'idea_index_card_presenter.parent_preview');
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

        return $this->obfuscatedOrRaw($raw, 'thought_content', 'idea_index_card_presenter.display_content');
    }

    /**
     * @return list<array{content: string, created_at_human: string}>
     */
    public function commentPreviewRows(): array
    {
        return $this->thought->childThoughts
            ->map(function (Thought $comment): array {
                $raw = Str::limit($comment->content, 200);
                $content = $this->obfuscatedOrRaw($raw, 'thought_comment_preview', 'idea_index_card_presenter.comment_preview');

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

    public function isVideoThought(): bool
    {
        return data_get($this->thought->metadata, 'type') === 'video';
    }

    public function videoLatestResearchUrl(): ?string
    {
        return $this->videoLatestResearchUrl;
    }

    public function transcriptStatusLabel(): ?string
    {
        if (! $this->isVideoThought()) {
            return null;
        }
        $status = data_get($this->thought->metadata, 'transcript_status');

        return match ($status) {
            VideoCaptureService::TRANSCRIPT_STATUS_PENDING => 'Fetching transcript',
            VideoCaptureService::TRANSCRIPT_STATUS_MANUAL => 'Transcript added manually',
            VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE => 'Transcript available',
            VideoCaptureService::TRANSCRIPT_STATUS_UNAVAILABLE => 'Transcript unavailable',
            VideoCaptureService::TRANSCRIPT_STATUS_FAILED => 'Transcript fetch failed',
            default => null,
        };
    }

    /**
     * External YouTube (or canonical) URL for “Open video” on the home feed.
     */
    public function videoOpenHref(): ?string
    {
        if (! $this->isVideoThought() || app(DemoMode::class)->enabled()) {
            return null;
        }
        $url = data_get($this->thought->metadata, 'video_url');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
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
