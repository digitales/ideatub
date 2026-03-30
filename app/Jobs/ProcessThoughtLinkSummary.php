<?php

namespace App\Jobs;

use App\Models\ThoughtLinkSummary;
use App\Services\LinkSummary\LinkSummaryFetcher;
use App\Services\LinkSummary\LinkSummaryGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProcessThoughtLinkSummary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 180;

    public function __construct(
        public readonly int $thoughtLinkSummaryId,
    ) {}

    public function handle(LinkSummaryFetcher $fetcher, LinkSummaryGenerator $generator): void
    {
        $summary = ThoughtLinkSummary::query()->find($this->thoughtLinkSummaryId);
        if ($summary === null) {
            return;
        }

        if ($summary->processing_status === 'summarized') {
            return;
        }

        $summary->update(['processing_status' => 'fetching']);

        $url = $summary->normalized_url;

        try {
            $payload = $fetcher->fetch($url);
        } catch (\Throwable $e) {
            if ($this->shouldRetryFetchThrowable($e)) {
                throw $e;
            }

            $summary->update([
                'processing_status' => 'failed',
                'failure_stage' => 'fetch',
                'failure_reason' => Str::limit($e->getMessage(), 255),
            ]);

            return;
        }

        $code = (int) $payload['status_code'];
        $normalizedUrl = (string) ($payload['normalized_url'] ?? $url);
        $normalizedUrlHash = sha1($normalizedUrl);

        if ($this->hasNormalizedUrlCollision($summary, $normalizedUrlHash)) {
            $summary->update([
                'processing_status' => 'failed',
                'failure_stage' => 'fetch',
                'failure_reason' => 'Resolved normalized URL collision with an existing summary.',
                'fetch_status_code' => $code,
            ]);

            return;
        }

        if ($code < 200 || $code >= 300) {
            if ($this->shouldRetryFetchStatus($code)) {
                throw new \RuntimeException('Retryable fetch failure: HTTP '.$code);
            }

            $summary->update([
                'normalized_url' => $normalizedUrl,
                'normalized_url_hash' => $normalizedUrlHash,
                'processing_status' => 'failed',
                'failure_stage' => 'fetch',
                'failure_reason' => Str::limit('HTTP '.$code, 255),
                'fetch_status_code' => $code,
            ]);

            return;
        }

        $title = (string) $payload['title'];
        $text = (string) $payload['visible_text'];
        $fingerprint = (string) $payload['content_fingerprint'];

        $thin = mb_strlen(trim($text)) < 80;
        $thinNote = $thin ? 'Very little visible text extracted from the page; summary confidence is low.' : null;

        $titleForModel = $title !== '' ? $title : 'Untitled';

        try {
            $gen = $generator->generate(
                $titleForModel,
                $text,
                (string) ($summary->source_excerpt ?? ''),
            );
        } catch (\Throwable $e) {
            if ($this->shouldRetrySummarizeThrowable()) {
                throw $e;
            }

            $summary->update([
                'normalized_url' => $normalizedUrl,
                'normalized_url_hash' => $normalizedUrlHash,
                'processing_status' => 'failed',
                'failure_stage' => 'summarize',
                'failure_reason' => Str::limit($e->getMessage(), 255),
                'fetch_status_code' => $code,
                'content_fingerprint' => $fingerprint !== '' ? $fingerprint : null,
            ]);

            return;
        }

        $resolvedTitle = Str::squish((string) ($gen['title'] ?? ''));
        $summaryText = trim((string) ($gen['summary_text'] ?? ''));
        $whyItMatters = trim((string) ($gen['why_it_matters'] ?? ''));

        if ($resolvedTitle === '' || $summaryText === '') {
            $summary->update([
                'normalized_url' => $normalizedUrl,
                'normalized_url_hash' => $normalizedUrlHash,
                'processing_status' => 'failed',
                'failure_stage' => 'summarize',
                'failure_reason' => 'Generated summary was missing required title or summary_text.',
                'fetch_status_code' => $code,
                'content_fingerprint' => $fingerprint !== '' ? $fingerprint : null,
            ]);

            return;
        }

        $qualityNotes = isset($gen['quality_notes']) && $gen['quality_notes'] !== null && $gen['quality_notes'] !== ''
            ? (string) $gen['quality_notes']
            : null;

        if ($thinNote !== null) {
            $qualityNotes = $qualityNotes !== null ? $thinNote.' '.$qualityNotes : $thinNote;
        }

        $summary->update([
            'normalized_url' => $normalizedUrl,
            'normalized_url_hash' => $normalizedUrlHash,
            'processing_status' => 'summarized',
            'fetch_status_code' => $code,
            'resolved_title' => $resolvedTitle,
            'summary_text' => $summaryText,
            'support_judgment' => (string) $gen['support_judgment'],
            'why_it_matters' => $whyItMatters,
            'quality_notes' => $qualityNotes,
            'usefulness_score' => (int) $gen['usefulness_score'],
            'content_fingerprint' => $fingerprint !== '' ? $fingerprint : null,
            'processed_at' => now(),
            'failure_stage' => null,
            'failure_reason' => null,
        ]);
    }

    private function hasNormalizedUrlCollision(ThoughtLinkSummary $summary, string $normalizedUrlHash): bool
    {
        if ($summary->normalized_url_hash === $normalizedUrlHash) {
            return false;
        }

        $query = ThoughtLinkSummary::query()
            ->whereKeyNot($summary->id)
            ->where('source_thought_id', $summary->source_thought_id)
            ->where('normalized_url_hash', $normalizedUrlHash);

        if ($summary->parent_research_thought_id === null) {
            $query->whereNull('parent_research_thought_id');
        } else {
            $query->where('parent_research_thought_id', $summary->parent_research_thought_id);
        }

        return $query->exists();
    }

    private function shouldRetryFetchThrowable(\Throwable $e): bool
    {
        return ! ($e instanceof InvalidArgumentException) && $this->attempts() < $this->tries;
    }

    private function shouldRetryFetchStatus(int $statusCode): bool
    {
        $retryableStatuses = [408, 425, 429, 500, 502, 503, 504];

        return in_array($statusCode, $retryableStatuses, true) && $this->attempts() < $this->tries;
    }

    private function shouldRetrySummarizeThrowable(): bool
    {
        return $this->attempts() < $this->tries;
    }
}
