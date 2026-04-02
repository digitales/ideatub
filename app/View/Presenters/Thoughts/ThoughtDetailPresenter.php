<?php

namespace App\View\Presenters\Thoughts;

use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Services\DemoMode;
use App\Services\Video\VideoCaptureService;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use App\View\Presenters\Email\EmailMetadataPresenter;
use App\View\Presenters\Email\NewsletterResearchStatusPresenter;
use Illuminate\Support\Facades\Log;

/**
 * View contract for the thought detail (`idea.show`) page.
 *
 * @phpstan-type SenderRuleContext array{
 *     enabled: bool,
 *     sender_available: bool,
 *     stored_email_type: string|null,
 *     stored_email_id: int|null,
 *     raw_sender: string|null,
 *     normalized_sender: string|null,
 *     rule: \App\Models\EmailSenderRule|null
 * }
 */
final class ThoughtDetailPresenter
{
    use ObfuscatesDemoText;

    private const FETCH_TRANSCRIPT_RETRYABLE_STATUSES = [
        VideoCaptureService::TRANSCRIPT_STATUS_UNAVAILABLE,
        VideoCaptureService::TRANSCRIPT_STATUS_FAILED,
    ];

    private bool $videoLatestResearchUrlResolved = false;

    private ?string $resolvedVideoLatestResearchUrl = null;

    private bool $videoTranscriptTextResolved = false;

    private ?string $resolvedVideoTranscriptText = null;

    /**
     * @param  array<string, mixed>|null  $senderRuleContext
     * @param  array{full_research_url: string, root_html: string, section_html_chunks: array<int, string>}|null  $emailResearchPreview
     * @param  array{subject: string, sender: string, url: string}|null  $relatedEmailCard
     */
    private function __construct(
        private readonly Thought $thought,
        private readonly ?string $contentHtml,
        private readonly array $documentSectionHtmlChunks,
        private readonly ?string $linkedResearchUrl,
        private readonly ?array $emailResearchPreview,
        private readonly ?NewsletterResearchStatusPresenter $newsletterResearchStatus,
        private readonly ?array $senderRuleContext,
        private readonly ?EmailMetadataPresenter $emailMetadata,
        private readonly ?ImportedEmail $importedEmailForBody,
        private readonly ?array $relatedEmailCard
    ) {}

    /**
     * @param  array<string, mixed>|null  $senderRuleContext
     * @param  array{full_research_url: string, root_html: string, section_html_chunks: array<int, string>}|null  $emailResearchPreview
     * @param  array{subject: string, sender: string, url: string}|null  $relatedEmailCard
     */
    public static function forShow(
        Thought $thought,
        ?string $contentHtml,
        array $documentSectionHtmlChunks,
        ?string $linkedResearchUrl,
        ?array $emailResearchPreview,
        ?NewsletterResearchStatusPresenter $newsletterResearchStatus,
        ?array $senderRuleContext,
        ?EmailMetadataPresenter $emailMetadata,
        ?ImportedEmail $importedEmailForBody,
        ?array $relatedEmailCard = null
    ): self {
        return new self(
            $thought,
            $contentHtml,
            $documentSectionHtmlChunks,
            $linkedResearchUrl,
            $emailResearchPreview,
            $newsletterResearchStatus,
            $senderRuleContext,
            $emailMetadata,
            $importedEmailForBody,
            $relatedEmailCard
        );
    }

    public function thought(): Thought
    {
        return $this->thought;
    }

    public function isEmailThought(): bool
    {
        return $this->thought->source === 'email';
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

        try {
            return $this->demoText($url, 'video_canonical_url');
        } catch (\Throwable $e) {
            $this->logDemoObfuscationFailure(
                boundary: 'thought_detail_presenter.video_canonical_url',
                context: 'video_canonical_url',
                exception: $e
            );

            return 'Demo content hidden';
        }
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

        return VideoCaptureService::transcriptStatusHumanLabel(
            data_get($this->thought->metadata, 'transcript_status')
        );
    }

    /**
     * @return list<array{label: string, value: string, href: ?string}>
     */
    public function videoMetadataLabeledRows(): array
    {
        if (! $this->isVideoThought()) {
            return [];
        }

        return VideoThoughtMetadataPresenter::forVideoRoot($this->thought)->labeledRows();
    }

    public function hasTranscriptTextPresent(): bool
    {
        if (! $this->isVideoThought()) {
            return false;
        }

        foreach ($this->thought->comments as $comment) {
            if (
                data_get($comment->metadata, 'video_section_type') !==
                'transcript'
            ) {
                continue;
            }
            $raw = trim((string) ($comment->content ?? ''));
            $raw = preg_replace("/^##\s+Transcript\s*/im", '', $raw) ?? $raw;

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

    public function videoTranscriptText(): ?string
    {
        if (! $this->isVideoThought()) {
            return null;
        }

        if ($this->videoTranscriptTextResolved) {
            return $this->resolvedVideoTranscriptText;
        }

        $this->videoTranscriptTextResolved = true;

        $transcriptChild = $this->thought->comments->first(
            fn (Thought $comment): bool => data_get(
                $comment->metadata,
                'video_section_type'
            ) === 'transcript'
        );

        if ($transcriptChild === null) {
            $this->resolvedVideoTranscriptText = null;

            return null;
        }

        $raw = trim((string) ($transcriptChild->content ?? ''));
        $raw = preg_replace("/^##\s+Transcript\s*/im", '', $raw) ?? $raw;
        $raw = trim($raw);

        if ($raw === '') {
            $this->resolvedVideoTranscriptText = null;

            return null;
        }

        try {
            $this->resolvedVideoTranscriptText =
                $this->demoText($raw, 'video_transcript_text') ??
                'Demo content hidden';
        } catch (\Throwable $e) {
            $this->logDemoObfuscationFailure(
                boundary: 'thought_detail_presenter.video_transcript_text',
                context: 'video_transcript_text',
                exception: $e,
                subjectThoughtId: $transcriptChild->id
            );

            $this->resolvedVideoTranscriptText = 'Demo content hidden';
        }

        return $this->resolvedVideoTranscriptText;
    }

    public function videoLatestResearchUrl(): ?string
    {
        if (! $this->isVideoThought()) {
            return null;
        }

        if ($this->videoLatestResearchUrlResolved) {
            return $this->resolvedVideoLatestResearchUrl;
        }

        $this->videoLatestResearchUrlResolved = true;

        $id = data_get($this->thought->metadata, 'research_thought_id');
        if (! is_string($id) || $id === '') {
            $this->resolvedVideoLatestResearchUrl = null;

            return null;
        }

        $research = Thought::query()->find($id);
        if (
            $research === null ||
            (int) $research->user_id !== (int) $this->thought->user_id
        ) {
            $this->resolvedVideoLatestResearchUrl = null;

            return null;
        }
        if (data_get($research->metadata, 'type') !== 'research') {
            $this->resolvedVideoLatestResearchUrl = null;

            return null;
        }

        $this->resolvedVideoLatestResearchUrl = route(
            'idea.research.show',
            $research
        );

        return $this->resolvedVideoLatestResearchUrl;
    }

    public function showVideoResearchPending(): bool
    {
        return $this->isVideoThought() && $this->thought->isResearchPending();
    }

    public function showVideoResearchNowHint(): bool
    {
        if (
            ! $this->isVideoThought() ||
            $this->videoLatestResearchUrl() !== null
        ) {
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
        $meta = is_array($this->thought->metadata)
            ? $this->thought->metadata
            : [];
        if (
            ! empty(
                $meta[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING]
            )
        ) {
            return false;
        }

        $status = data_get($this->thought->metadata, 'transcript_status');

        return in_array(
            $status,
            [
                VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                VideoCaptureService::TRANSCRIPT_STATUS_MANUAL,
                VideoCaptureService::TRANSCRIPT_STATUS_UNAVAILABLE,
                VideoCaptureService::TRANSCRIPT_STATUS_FAILED,
            ],
            true
        );
    }

    public function showVideoRerunResearchHint(): bool
    {
        return $this->isVideoThought() &&
            $this->videoLatestResearchUrl() !== null &&
            ! app(DemoMode::class)->enabled() &&
            ! $this->thought->isResearchPending();
    }

    public function showFetchTranscriptAction(): bool
    {
        if (! $this->isVideoThought() || app(DemoMode::class)->enabled()) {
            return false;
        }

        if (
            $this->videoFetchTranscriptActionUrl() === null ||
            $this->hasTranscriptTextPresent()
        ) {
            return false;
        }

        $status = data_get($this->thought->metadata, 'transcript_status');

        return in_array(
            $status,
            self::FETCH_TRANSCRIPT_RETRYABLE_STATUSES,
            true
        );
    }

    /**
     * Offer pasting a transcript on the thought detail page when none is stored yet.
     */
    public function showAddTranscriptForm(): bool
    {
        if (! $this->isVideoThought() || app(DemoMode::class)->enabled()) {
            return false;
        }

        return ! $this->hasTranscriptTextPresent();
    }

    public function contentHtml(): ?string
    {
        return $this->contentHtml;
    }

    /**
     * @return array<int, string>
     */
    public function documentSectionHtmlChunks(): array
    {
        return $this->documentSectionHtmlChunks;
    }

    public function linkedResearchUrl(): ?string
    {
        return $this->linkedResearchUrl;
    }

    /**
     * @return array{full_research_url: string, root_html: string, section_html_chunks: array<int, string>}|null
     */
    public function emailResearchPreview(): ?array
    {
        return $this->emailResearchPreview;
    }

    public function newsletterResearchStatus(): ?NewsletterResearchStatusPresenter
    {
        return $this->newsletterResearchStatus;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function senderRuleContext(): ?array
    {
        return $this->senderRuleContext;
    }

    public function emailMetadata(): ?EmailMetadataPresenter
    {
        return $this->emailMetadata;
    }

    /**
     * Source email when this thought (e.g. video) carries merged email linkage in metadata/source_metadata.
     *
     * @return array{subject: string, sender: string, url: string}|null
     */
    public function relatedEmailCard(): ?array
    {
        return $this->relatedEmailCard;
    }

    /**
     * @return array<int, array{content: string, created_at_human: string}>
     */
    public function replyRows(): array
    {
        $comments = $this->thought->comments;
        if ($this->isVideoThought()) {
            $comments = $comments
                ->filter(
                    fn (Thought $c) => data_get(
                        $c->metadata,
                        'video_section_type'
                    ) !== 'transcript'
                )
                ->values();
        }

        $comments = $comments
            ->filter(fn (Thought $c) => ! $this->isStructuredDocumentSection($c))
            ->values();

        return $comments
            ->map(function (Thought $comment): array {
                try {
                    $content = $this->demoText(
                        $comment->content,
                        'thought_comment_preview'
                    );
                } catch (\Throwable $e) {
                    $this->logDemoObfuscationFailure(
                        boundary: 'thought_detail_presenter.reply_rows',
                        context: 'thought_comment_preview',
                        exception: $e,
                        subjectThoughtId: $comment->id
                    );

                    $content = 'Demo content hidden';
                }

                return [
                    'content' => $content ?? 'Demo content hidden',
                    'created_at_human' => $comment->created_at->diffForHumans(),
                ];
            })
            ->all();
    }

    public function emailBodyText(): string
    {
        if ($this->thought->source !== 'email') {
            $raw = $this->thought->content ?? '';

            return $raw;
        }

        $body = $this->importedEmailForBody?->body_text;
        $raw =
            is_string($body) && $body !== ''
                ? $body
                : $this->thought->content ?? '';

        try {
            $obfuscated = $this->demoText($raw, 'email_body_text');
        } catch (\Throwable $e) {
            $this->logDemoObfuscationFailure(
                boundary: 'thought_detail_presenter.email_body_text',
                context: 'email_body_text',
                exception: $e
            );

            return 'Demo content hidden';
        }

        return $obfuscated ?? 'Demo content hidden';
    }

    private function logDemoObfuscationFailure(
        string $boundary,
        string $context,
        \Throwable $exception,
        ?string $subjectThoughtId = null
    ): void {
        Log::warning(
            'Demo obfuscation failed for thought detail presenter field.',
            [
                'boundary' => $boundary,
                'context' => $context,
                'thought_id' => $this->thought->id,
                'subject_thought_id' => $subjectThoughtId,
                'exception' => $exception::class,
            ]
        );
    }

    private function isStructuredDocumentSection(Thought $comment): bool
    {
        $sectionIndex = data_get($comment->source_metadata, 'section_index');

        return $sectionIndex !== null && trim((string) $sectionIndex) !== '';
    }
}
