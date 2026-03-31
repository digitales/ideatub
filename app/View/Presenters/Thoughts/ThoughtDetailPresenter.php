<?php

namespace App\View\Presenters\Thoughts;

use App\Models\ImportedEmail;
use App\Models\Thought;
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

    /**
     * @param  array<string, mixed>|null  $senderRuleContext
     * @param  array{full_research_url: string, root_html: string, section_html_chunks: array<int, string>}|null  $emailResearchPreview
     */
    private function __construct(
        private readonly Thought $thought,
        private readonly ?string $contentHtml,
        private readonly ?string $linkedResearchUrl,
        private readonly ?array $emailResearchPreview,
        private readonly ?NewsletterResearchStatusPresenter $newsletterResearchStatus,
        private readonly ?array $senderRuleContext,
        private readonly ?EmailMetadataPresenter $emailMetadata,
        private readonly ?ImportedEmail $importedEmailForBody,
    ) {}

    /**
     * @param  array<string, mixed>|null  $senderRuleContext
     * @param  array{full_research_url: string, root_html: string, section_html_chunks: array<int, string>}|null  $emailResearchPreview
     */
    public static function forShow(
        Thought $thought,
        ?string $contentHtml,
        ?string $linkedResearchUrl,
        ?array $emailResearchPreview,
        ?NewsletterResearchStatusPresenter $newsletterResearchStatus,
        ?array $senderRuleContext,
        ?EmailMetadataPresenter $emailMetadata,
        ?ImportedEmail $importedEmailForBody,
    ): self {
        return new self(
            $thought,
            $contentHtml,
            $linkedResearchUrl,
            $emailResearchPreview,
            $newsletterResearchStatus,
            $senderRuleContext,
            $emailMetadata,
            $importedEmailForBody,
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

    public function contentHtml(): ?string
    {
        return $this->contentHtml;
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
     * @return array<int, array{content: string, created_at_human: string}>
     */
    public function replyRows(): array
    {
        return $this->thought->comments
            ->map(function (Thought $comment): array {
                try {
                    $content = $this->demoText($comment->content, 'thought_comment_preview');
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
        $raw = (is_string($body) && $body !== '') ? $body : ($this->thought->content ?? '');

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

    private function logDemoObfuscationFailure(string $boundary, string $context, \Throwable $exception, ?string $subjectThoughtId = null): void
    {
        Log::warning('Demo obfuscation failed for thought detail presenter field.', [
            'boundary' => $boundary,
            'context' => $context,
            'thought_id' => $this->thought->id,
            'subject_thought_id' => $subjectThoughtId,
            'exception' => $exception::class,
        ]);
    }
}
