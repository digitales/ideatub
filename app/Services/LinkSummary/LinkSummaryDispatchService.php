<?php

namespace App\Services\LinkSummary;

use App\Jobs\ProcessThoughtLinkSummary;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Models\ThoughtLinkSummary;

class LinkSummaryDispatchService
{
    public function __construct(
        private readonly NewsletterEditorialLinkCandidateBuilder $candidateBuilder,
    ) {}

    /**
     * @param  list<array{url: string, type: string}|array<string, mixed>|string>  $extractedLinks
     */
    public function queueNewsletterEditorialLinks(
        Thought $emailThought,
        Thought $researchThought,
        ImportedEmail|CapturedInboundEmail $storedEmail,
        string $bodyText,
        array $extractedLinks,
    ): void {
        $candidates = $this->candidateBuilder->build($bodyText, $extractedLinks);
        [$storedType, $storedId] = $this->storedEmailIdentity($storedEmail);
        $sectionRanks = [];

        foreach ($candidates as $candidate) {
            $sourceClassification = (string) ($candidate['classification'] ?? 'editorial');
            $shouldQueueJob = $this->shouldDispatchSummarizationJob($sourceClassification);
            $classification = $this->storedClassification($sourceClassification);
            $sectionRank = $this->nextSectionRank(
                $sectionRanks,
                (string) ($candidate['newsletter_section_label'] ?? '')
            );
            $row = ThoughtLinkSummary::query()->firstOrNew([
                'source_thought_id' => $emailThought->id,
                'normalized_url_hash' => $candidate['normalized_url_hash'],
                'parent_research_thought_id' => $researchThought->id,
            ]);

            $existingStatus = $row->exists ? (string) $row->processing_status : null;
            $row->fill([
                'user_id' => (int) $emailThought->user_id,
                'source_type' => 'email_newsletter',
                'stored_email_type' => $storedType,
                'stored_email_id' => $storedId,
                'original_url' => $candidate['original_url'],
                'normalized_url' => $candidate['normalized_url'],
                'newsletter_section_label' => $candidate['newsletter_section_label'],
                'newsletter_section_order' => $candidate['newsletter_section_order'],
                'source_excerpt' => $candidate['source_excerpt'],
                'classification' => $classification,
                'section_rank' => $sectionRank,
            ]);

            if ($shouldQueueJob) {
                $row->processing_status = $this->queueableProcessingStatus($existingStatus);
            } else {
                $row->processing_status = 'excluded';
            }

            $row->save();

            if ($shouldQueueJob && $this->shouldDispatchForStatus($existingStatus)) {
                ProcessThoughtLinkSummary::dispatch($row->id);
            }
        }
    }

    private function shouldDispatchSummarizationJob(string $classification): bool
    {
        return $classification === 'editorial' || $classification === 'unknown';
    }

    private function storedClassification(string $classification): string
    {
        if ($classification === 'unknown') {
            return 'editorial';
        }

        return $classification;
    }

    private function nextSectionRank(array &$sectionRanks, string $sectionLabel): int
    {
        if ($sectionLabel === '') {
            $sectionLabel = 'Uncategorized editorial links';
        }

        $sectionRanks[$sectionLabel] = ($sectionRanks[$sectionLabel] ?? 0) + 1;

        return $sectionRanks[$sectionLabel];
    }

    private function queueableProcessingStatus(?string $existingStatus): string
    {
        if (in_array($existingStatus, ['fetching', 'summarized'], true)) {
            return $existingStatus;
        }

        return 'queued';
    }

    private function shouldDispatchForStatus(?string $existingStatus): bool
    {
        return ! in_array($existingStatus, ['queued', 'fetching', 'summarized'], true);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function storedEmailIdentity(ImportedEmail|CapturedInboundEmail $storedEmail): array
    {
        if ($storedEmail instanceof CapturedInboundEmail) {
            return ['captured_inbound_email', (int) $storedEmail->id];
        }

        return ['imported_email', (int) $storedEmail->id];
    }
}
