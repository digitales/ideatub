<?php

namespace App\View\Presenters\Thoughts;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Services\DemoMode;
use App\Services\Video\VideoCaptureService;
use App\View\Presenters\Concerns\EnsuresPresenterDataIsLoaded;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use App\View\Presenters\Email\NewsletterResearchStatusPresenter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Per-card view state for stream feeds (main + typed collections).
 */
final class StreamThoughtCardPresenter
{
    use EnsuresPresenterDataIsLoaded;
    use ObfuscatesDemoText;

    private const FETCH_TRANSCRIPT_RETRYABLE_STATUSES = [
        VideoCaptureService::TRANSCRIPT_STATUS_UNAVAILABLE,
        VideoCaptureService::TRANSCRIPT_STATUS_FAILED,
    ];

    private function __construct(
        private readonly Thought $thought,
        private readonly ?ResearchShare $share,
        private readonly string $activityAtHuman,
        private readonly bool $ownerMayInlineEdit,
        private readonly bool $showFullSections,
        private readonly ?NewsletterResearchStatusPresenter $newsletterResearchStatus,
        private readonly ?string $videoLatestResearchUrl,
    ) {
        $this->requireRelationLoaded($this->thought, 'comments');
    }

    public static function fromThought(
        Thought $thought,
        ?ResearchShare $share,
        bool $showFullSections,
        ?NewsletterResearchStatusPresenter $newsletterResearchStatus = null,
        ?string $videoLatestResearchUrl = null,
    ): self {
        $activityAt = self::resolveStreamActivityAt($thought);
        $activityHuman = $activityAt->diffForHumans();

        $userId = Auth::id();
        $ownerMayInlineEdit = Auth::check() && $userId !== null && (int) $userId === (int) $thought->user_id;

        return new self(
            $thought,
            $share,
            $activityHuman,
            $ownerMayInlineEdit,
            $showFullSections,
            $newsletterResearchStatus,
            $videoLatestResearchUrl,
        );
    }

    public function thought(): Thought
    {
        return $this->thought;
    }

    public function share(): ?ResearchShare
    {
        return $this->share;
    }

    public function activityAtHuman(): string
    {
        return $this->activityAtHuman;
    }

    public function displayContent(): string
    {
        $raw = (string) ($this->thought->content ?? '');

        return $this->obfuscatedOrRaw($raw, 'thought_content', 'stream_thought_card_presenter.display_content');
    }

    /**
     * @return list<array{content: string, created_at_human: string}>
     */
    public function commentPreviewRows(): array
    {
        return $this->streamCommentCandidates()
            ->map(function (Thought $comment): array {
                $raw = $this->showFullSections
                    ? (string) ($comment->content ?? '')
                    : Str::limit($comment->content, 200);
                $content = $this->obfuscatedOrRaw($raw, 'thought_comment_preview', 'stream_thought_card_presenter.comment_preview');

                return [
                    'content' => $content,
                    'created_at_human' => $comment->created_at->diffForHumans(),
                ];
            })
            ->all();
    }

    public function isVideoThought(): bool
    {
        return data_get($this->thought->metadata, 'type') === 'video';
    }

    public function videoCanonicalUrl(): ?string
    {
        $url = $this->rawVideoCanonicalUrl();
        if ($url === null) {
            return null;
        }

        return $this->obfuscatedOrRaw($url, 'video_canonical_url', 'stream_thought_card_presenter.video_canonical_url');
    }

    public function videoCanonicalHref(): ?string
    {
        if (app(DemoMode::class)->enabled()) {
            return null;
        }

        return $this->rawVideoCanonicalUrl();
    }

    public function videoResearchActionUrl(): ?string
    {
        return $this->videoCanonicalHref();
    }

    public function videoFetchTranscriptActionUrl(): ?string
    {
        return $this->videoCanonicalHref();
    }

    private function rawVideoCanonicalUrl(): ?string
    {
        if (! $this->isVideoThought()) {
            return null;
        }

        $url = data_get($this->thought->metadata, 'video_url');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
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

    public function hasTranscriptTextPresent(): bool
    {
        if (! $this->isVideoThought()) {
            return false;
        }

        foreach ($this->thought->comments as $comment) {
            if (data_get($comment->metadata, 'video_section_type') !== 'transcript') {
                continue;
            }
            $raw = trim((string) ($comment->content ?? ''));
            $raw = preg_replace('/^##\s+Transcript\s*/im', '', $raw) ?? $raw;

            if (trim($raw) !== '') {
                return true;
            }
        }

        return false;
    }

    public function transcriptPresenceLabel(): ?string
    {
        if (! $this->isVideoThought()) {
            return null;
        }

        if ($this->hasTranscriptTextPresent()) {
            return 'Transcript text present';
        }

        return null;
    }

    public function videoLatestResearchUrl(): ?string
    {
        if (! $this->isVideoThought()) {
            return null;
        }

        return $this->videoLatestResearchUrl;
    }

    public function showVideoResearchPending(): bool
    {
        return $this->isVideoThought() && $this->thought->isResearchPending();
    }

    public function showVideoResearchNowHint(): bool
    {
        if (! $this->isVideoThought() || $this->videoLatestResearchUrl() !== null) {
            return false;
        }
        if ($this->showFetchTranscriptAction()) {
            return false;
        }
        if (app(DemoMode::class)->enabled()) {
            return false;
        }
        if ($this->thought->isResearchPending()) {
            return false;
        }
        $meta = is_array($this->thought->metadata) ? $this->thought->metadata : [];
        if (! empty($meta[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING])) {
            return false;
        }

        $status = data_get($this->thought->metadata, 'transcript_status');

        return in_array($status, [
            VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
            VideoCaptureService::TRANSCRIPT_STATUS_MANUAL,
            VideoCaptureService::TRANSCRIPT_STATUS_UNAVAILABLE,
            VideoCaptureService::TRANSCRIPT_STATUS_FAILED,
        ], true);
    }

    public function showVideoRerunResearchHint(): bool
    {
        return $this->isVideoThought()
            && $this->videoLatestResearchUrl() !== null
            && ! app(DemoMode::class)->enabled()
            && ! $this->thought->isResearchPending();
    }

    public function showFetchTranscriptAction(): bool
    {
        if (! $this->isVideoThought() || app(DemoMode::class)->enabled()) {
            return false;
        }

        if ($this->videoFetchTranscriptActionUrl() === null || $this->hasTranscriptTextPresent()) {
            return false;
        }

        $status = data_get($this->thought->metadata, 'transcript_status');

        return in_array($status, self::FETCH_TRANSCRIPT_RETRYABLE_STATUSES, true);
    }

    /**
     * @return Collection<int, Thought>
     */
    private function streamCommentCandidates(): Collection
    {
        $comments = $this->thought->comments;
        if ($this->isVideoThought()) {
            return $comments->filter(
                fn (Thought $c) => ! in_array(
                    (string) data_get($c->metadata, 'video_section_type'),
                    ['transcript', 'research'],
                    true
                )
            )->values();
        }

        return $comments;
    }

    public function editable(): bool
    {
        if (app(DemoMode::class)->enabled()) {
            return false;
        }

        return $this->ownerMayInlineEdit;
    }

    public function showFullSections(): bool
    {
        return $this->showFullSections;
    }

    public function newsletterResearchStatus(): ?NewsletterResearchStatusPresenter
    {
        return $this->newsletterResearchStatus;
    }

    public function showViewFormattedLink(): bool
    {
        if ($this->isVideoThought()) {
            return false;
        }

        return $this->thought->relationLoaded('comments') && $this->thought->comments->isNotEmpty();
    }

    public function showCommentsBlock(): bool
    {
        if ($this->isVideoThought()) {
            return $this->streamCommentCandidates()->isNotEmpty();
        }

        return $this->showViewFormattedLink();
    }

    private static function resolveStreamActivityAt(Thought $thought): Carbon
    {
        if (($thought->source ?? null) === 'jira') {
            $jiraUpdatedAt = data_get($thought->source_metadata, 'jira_updated_at');
            if (is_string($jiraUpdatedAt) && trim($jiraUpdatedAt) !== '') {
                try {
                    return Carbon::parse($jiraUpdatedAt);
                } catch (\Throwable) {
                    // fall through to created_at
                }
            }
        }

        return $thought->created_at;
    }

    private function obfuscatedOrRaw(string $value, string $context, string $boundary): string
    {
        try {
            return $this->demoText($value, $context) ?? '';
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for stream thought card presenter field.', [
                'boundary' => $boundary,
                'context' => $context,
                'thought_id' => $this->thought->id,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }
}
