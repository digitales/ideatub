<?php

namespace App\Jobs;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Services\Email\EmailLinkExtractor;
use App\Services\Email\EmailNewsletterResearchService;
use App\Services\LinkSummary\LinkSummaryDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Queued follow-up for stored Fastmail rows and Postmark capture (Task 5.3).
 */
class ProcessExtraEmailResearch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 600;

    public function __construct(
        public readonly ?int $importedEmailId = null,
        public readonly ?int $capturedInboundEmailId = null
    ) {
        if (
            ($importedEmailId === null) ===
            ($capturedInboundEmailId === null)
        ) {
            throw new \InvalidArgumentException(
                'Exactly one of importedEmailId or capturedInboundEmailId must be set.'
            );
        }
    }

    public function handle(
        EmailNewsletterResearchService $researchService,
        EmailLinkExtractor $linkExtractor
    ): void {
        $lock = Cache::lock($this->lockKey(), $this->timeout + 60);
        if (! $lock->get()) {
            $this->release($this->contentionReleaseDelay());

            return;
        }

        try {
            $this->handleLocked($researchService, $linkExtractor);
        } finally {
            $lock->release();
        }
    }

    private function handleLocked(
        EmailNewsletterResearchService $researchService,
        EmailLinkExtractor $linkExtractor
    ): void {
        $stored = $this->resolveStoredEmail();
        if ($stored === null) {
            Log::warning('ProcessExtraEmailResearch: stored email not found.', [
                'imported_email_id' => $this->importedEmailId,
                'captured_inbound_email_id' => $this->capturedInboundEmailId,
            ]);

            return;
        }

        $thought = $stored->thought;
        if ($thought === null) {
            throw new \RuntimeException(
                'Stored email has no linked email thought.'
            );
        }

        $links = $linkExtractor->linksFromProcessingMetadata(
            $stored->processing_metadata_json
        );
        if ($links === []) {
            $links = $linkExtractor->extractFromContent(
                trim((string) ($stored->body_text ?? '')),
                null
            );
        }

        $ingestionSource =
            $stored instanceof CapturedInboundEmail ? 'postmark' : 'fastmail';

        $existingResearchThought = $this->resolveExistingResearchThought($stored);
        if ($existingResearchThought instanceof Thought) {
            $status = $this->existingResearchStatus($stored);
            $stored->processing_status = $status;
            $stored->save();

            $this->mergeEmailThoughtNewsletterStatus(
                $thought,
                $status,
                null,
                (string) $existingResearchThought->id
            );

            app(LinkSummaryDispatchService::class)->queueNewsletterEditorialLinks(
                $thought,
                $existingResearchThought,
                $stored,
                trim((string) ($stored->body_text ?? '')),
                $links
            );

            return;
        }

        $result = $researchService->createFromEmailThought(
            $thought,
            $stored,
            $ingestionSource,
            $links
        );

        $stored->refresh();
        $thought->refresh();

        if ($result['status'] === 'skipped') {
            $stored->processing_status = 'research_skipped';
            $stored->save();
            $this->mergeEmailThoughtNewsletterStatus(
                $thought,
                'research_skipped',
                $result['reason'] ?? null
            );

            return;
        }

        $degraded = (bool) ($result['degraded'] ?? false);
        $stored->processing_status = $degraded
            ? 'research_partial'
            : 'research_completed';
        $stored->save();

        $researchThought = $result['research_thought'] ?? null;
        $this->mergeEmailThoughtNewsletterStatus(
            $thought,
            $degraded ? 'research_partial' : 'research_completed',
            null,
            $researchThought instanceof Thought ? (string) $researchThought->id : null
        );

        if ($researchThought instanceof Thought) {
            app(LinkSummaryDispatchService::class)->queueNewsletterEditorialLinks(
                $thought,
                $researchThought,
                $stored,
                trim((string) ($stored->body_text ?? '')),
                $links
            );
        }
    }

    private function resolveExistingResearchThought(
        ImportedEmail|CapturedInboundEmail $stored
    ): ?Thought {
        $researchThoughtId = $stored->research_thought_id;
        if (! is_string($researchThoughtId) || $researchThoughtId === '') {
            return null;
        }

        return Thought::query()
            ->whereKey($researchThoughtId)
            ->where('user_id', $stored->user_id)
            ->first();
    }

    /**
     * @return 'research_partial'|'research_completed'
     */
    private function existingResearchStatus(
        ImportedEmail|CapturedInboundEmail $stored
    ): string {
        $meta = $stored->processing_metadata_json ?? [];
        $status = data_get($meta, 'newsletter_research.status');

        if ($status === 'research_partial') {
            return 'research_partial';
        }

        return 'research_completed';
    }

    private function lockKey(): string
    {
        if ($this->importedEmailId !== null) {
            return 'process-extra-email-research:imported:'.
                $this->importedEmailId;
        }

        return 'process-extra-email-research:captured:'.
            $this->capturedInboundEmailId;
    }

    private function resolveStoredEmail(): ImportedEmail|CapturedInboundEmail|null
    {
        if ($this->importedEmailId !== null) {
            return ImportedEmail::query()->find($this->importedEmailId);
        }

        return CapturedInboundEmail::query()->find(
            $this->capturedInboundEmailId
        );
    }

    private function contentionReleaseDelay(): int
    {
        if (is_array($this->backoff)) {
            $first = $this->backoff[0] ?? 60;

            return is_numeric($first) ? (int) $first : 60;
        }

        return $this->backoff;
    }

    /**
     * @param  'research_skipped'|'research_partial'|'research_completed'  $status
     */
    private function mergeEmailThoughtNewsletterStatus(
        Thought $thought,
        string $status,
        ?string $reason,
        ?string $researchThoughtId = null
    ): void {
        $meta = $thought->source_metadata ?? [];
        $block = [
            'status' => $status,
        ];
        if ($reason !== null) {
            $block['reason'] = $reason;
        }
        if ($researchThoughtId !== null) {
            $block['research_thought_id'] = (string) $researchThoughtId;
        }
        $meta['newsletter_research'] = $block;
        $thought->source_metadata = $meta;
        $thought->save();
    }
}
