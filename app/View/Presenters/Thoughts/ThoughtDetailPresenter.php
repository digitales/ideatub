<?php

namespace App\View\Presenters\Thoughts;

use App\Models\ImportedEmail;
use App\Models\Thought;
use App\View\Presenters\Email\EmailMetadataPresenter;
use App\View\Presenters\Email\NewsletterResearchStatusPresenter;

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

    public function emailBodyText(): string
    {
        if ($this->thought->source !== 'email') {
            return $this->thought->content;
        }

        $body = $this->importedEmailForBody?->body_text;

        return (is_string($body) && $body !== '') ? $body : $this->thought->content;
    }
}
