<?php

namespace App\Services\Email;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Services\ThoughtCaptureService;
use InvalidArgumentException;

class EmailNewsletterResearchService
{
    public function __construct(
        private readonly ThoughtCaptureService $thoughtCapture,
        private readonly YouTubeTranscriptService $youTubeTranscript,
    ) {}

    /**
     * @param  list<array{url: string, type: string}>  $extractedLinks
     * @return array{
     *     status: 'created'|'skipped',
     *     research_thought?: Thought|null,
     *     reason?: string,
     *     degraded?: bool
     * }
     */
    public function createFromEmailThought(
        Thought $emailThought,
        ImportedEmail|CapturedInboundEmail $storedEmail,
        string $ingestionSource,
        array $extractedLinks,
    ): array {
        if ((int) $emailThought->user_id !== (int) $storedEmail->user_id) {
            throw new InvalidArgumentException('Email thought and stored email must belong to the same user.');
        }

        $youtubeRows = $this->fetchYoutubeTranscripts($extractedLinks);

        $composite = $this->compositeText($storedEmail);
        $assessment = $this->assessInputQuality($composite, $extractedLinks, $youtubeRows);

        if (! $assessment['ok']) {
            $this->recordSkipMetadata($storedEmail, (string) $assessment['reason']);
            $storedEmail->save();

            return [
                'status' => 'skipped',
                'reason' => (string) $assessment['reason'],
                'research_thought' => null,
            ];
        }

        $degraded = $this->hasDegradedYoutubeFailure($youtubeRows);
        $markdown = $this->buildResearchMarkdown($storedEmail, $extractedLinks, $youtubeRows, $degraded);

        $sender = $this->resolveSenderEmail($storedEmail);
        [$storedType, $storedId, $planSlug] = $this->storedEmailIdentity($storedEmail);

        $project = (string) config('app.name', 'ideatub');

        $researchSourceMetadata = [
            'doc_type' => 'research',
            'stored_email_id' => $storedId,
            'stored_email_type' => $storedType,
            'email_thought_id' => $emailThought->id,
            'sender_email' => $sender,
            'ingestion_source' => $ingestionSource,
        ];

        $capture = $this->thoughtCapture->create([
            'content' => $markdown,
            'user_id' => (int) $emailThought->user_id,
            'source' => 'research',
            'source_metadata' => $researchSourceMetadata,
            'doc_type' => 'research',
            'plan_slug' => $planSlug,
            'project' => $project,
            'no_chunking' => true,
        ]);

        $research = $capture['thought'] ?? $capture['root'] ?? null;
        if (! $research instanceof Thought) {
            throw new \RuntimeException('ThoughtCaptureService did not return a research thought.');
        }

        $this->persistSuccessMetadata(
            $storedEmail,
            $emailThought,
            $research,
            $youtubeRows,
            $degraded,
        );

        return [
            'status' => 'created',
            'research_thought' => $research,
            'degraded' => $degraded,
        ];
    }

    /**
     * @param  list<array{url: string, type: string}>  $extractedLinks
     * @return list<array{url: string, result: array<string, mixed>}>
     */
    private function fetchYoutubeTranscripts(array $extractedLinks): array
    {
        $out = [];
        foreach ($extractedLinks as $link) {
            if (($link['type'] ?? '') !== 'youtube') {
                continue;
            }
            $url = $link['url'] ?? '';
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $out[] = [
                'url' => $url,
                'result' => $this->youTubeTranscript->fetchForUrl($url),
            ];
        }

        return $out;
    }

    private function compositeText(ImportedEmail|CapturedInboundEmail $storedEmail): string
    {
        $subject = trim((string) ($storedEmail->subject ?? ''));
        $body = trim((string) ($storedEmail->body_text ?? ''));
        if ($subject === '') {
            return $body;
        }
        if ($body === '') {
            return $subject;
        }

        return $subject."\n\n".$body;
    }

    /**
     * @param  list<array{url: string, type: string}>  $extractedLinks
     * @param  list<array{url: string, result: array<string, mixed>}>  $youtubeRows
     * @return array{ok: bool, reason?: string}
     */
    private function assessInputQuality(string $composite, array $extractedLinks, array $youtubeRows): array
    {
        $len = mb_strlen($composite);
        $words = str_word_count($composite);

        if ($this->hasSuccessfulTranscript($youtubeRows)) {
            return ['ok' => true];
        }

        if ($len >= 200 || $words >= 40) {
            return ['ok' => true];
        }

        if ($len >= 120 && count($extractedLinks) > 0) {
            return ['ok' => true];
        }

        $hasYoutube = $this->hasYoutubeLink($extractedLinks);
        if ($hasYoutube && $len < 80 && ! $this->anyTranscriptSucceeded($youtubeRows)) {
            return ['ok' => false, 'reason' => 'transcript_unavailable_and_body_too_short'];
        }

        if ($len < 20 && count($extractedLinks) === 0) {
            return ['ok' => false, 'reason' => 'insufficient_content'];
        }

        if (count($extractedLinks) === 0 && $len < 80) {
            return ['ok' => false, 'reason' => 'insufficient_content'];
        }

        if (count($extractedLinks) > 0 && $len >= 80) {
            return ['ok' => true];
        }

        return ['ok' => false, 'reason' => 'insufficient_content'];
    }

    /**
     * @param  list<array{url: string, result: array<string, mixed>}>  $youtubeRows
     */
    private function hasSuccessfulTranscript(array $youtubeRows): bool
    {
        foreach ($youtubeRows as $row) {
            $r = $row['result'];
            if (($r['ok'] ?? false) === true && isset($r['transcript']) && is_string($r['transcript'])) {
                if (mb_strlen(trim($r['transcript'])) > 10) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{url: string, result: array<string, mixed>}>  $youtubeRows
     */
    private function anyTranscriptSucceeded(array $youtubeRows): bool
    {
        foreach ($youtubeRows as $row) {
            if (($row['result']['ok'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{url: string, type: string}>  $extractedLinks
     */
    private function hasYoutubeLink(array $extractedLinks): bool
    {
        foreach ($extractedLinks as $link) {
            if (($link['type'] ?? '') === 'youtube') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{url: string, result: array<string, mixed>}>  $youtubeRows
     */
    private function hasDegradedYoutubeFailure(array $youtubeRows): bool
    {
        foreach ($youtubeRows as $row) {
            if (($row['result']['ok'] ?? false) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{url: string, type: string}>  $extractedLinks
     * @param  list<array{url: string, result: array<string, mixed>}>  $youtubeRows
     */
    private function buildResearchMarkdown(
        ImportedEmail|CapturedInboundEmail $storedEmail,
        array $extractedLinks,
        array $youtubeRows,
        bool $degraded,
    ): string {
        $subject = trim((string) ($storedEmail->subject ?? ''));
        $body = trim((string) ($storedEmail->body_text ?? ''));
        $title = $subject !== '' ? $subject : 'Newsletter research';

        $lines = [];
        $lines[] = '# '.$title;
        $lines[] = '';
        $lines[] = '## Email content';
        $lines[] = '';
        $lines[] = $body !== '' ? $body : '_No body text was available._';
        $lines[] = '';
        $lines[] = '## Extracted links';
        $lines[] = '';
        if ($extractedLinks === []) {
            $lines[] = '_No links were extracted._';
        } else {
            foreach ($extractedLinks as $link) {
                $u = (string) ($link['url'] ?? '');
                $t = (string) ($link['type'] ?? 'generic');
                if ($u === '') {
                    continue;
                }
                $lines[] = '- '.$u.' (`'.$t.'`)';
            }
        }
        $lines[] = '';
        $lines[] = '## YouTube transcripts';
        $lines[] = '';
        if ($youtubeRows === []) {
            $lines[] = '_No YouTube URLs were present._';
        } else {
            foreach ($youtubeRows as $row) {
                $url = $row['url'];
                $r = $row['result'];
                $lines[] = '### '.$url;
                $lines[] = '';
                if (($r['ok'] ?? false) === true && isset($r['transcript']) && is_string($r['transcript'])) {
                    $lines[] = $r['transcript'];
                } else {
                    $reason = is_string($r['reason'] ?? null) ? $r['reason'] : 'unknown';
                    $lines[] = '_Transcript unavailable ('.$reason.')_';
                }
                $lines[] = '';
            }
        }

        if ($degraded) {
            $lines[] = '## Notes';
            $lines[] = '';
            $lines[] = '_Some YouTube transcripts could not be retrieved; this document may be incomplete._';
        }

        return trim(implode("\n", $lines));
    }

    private function resolveSenderEmail(ImportedEmail|CapturedInboundEmail $storedEmail): string
    {
        if ($storedEmail instanceof CapturedInboundEmail) {
            return mb_strtolower(trim((string) $storedEmail->sender_email));
        }

        $from = $storedEmail->from_json[0] ?? null;
        if (is_array($from) && isset($from['email']) && is_string($from['email'])) {
            return mb_strtolower(trim($from['email']));
        }

        if ($storedEmail->rule_email !== null && trim((string) $storedEmail->rule_email) !== '') {
            return mb_strtolower(trim((string) $storedEmail->rule_email));
        }

        return '';
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function storedEmailIdentity(ImportedEmail|CapturedInboundEmail $storedEmail): array
    {
        if ($storedEmail instanceof CapturedInboundEmail) {
            return [
                'captured_inbound_email',
                (int) $storedEmail->id,
                'newsletter-captured-'.$storedEmail->id,
            ];
        }

        return [
            'imported_email',
            (int) $storedEmail->id,
            'newsletter-imported-'.$storedEmail->id,
        ];
    }

    private function recordSkipMetadata(ImportedEmail|CapturedInboundEmail $storedEmail, string $reason): void
    {
        $meta = $storedEmail->processing_metadata_json ?? [];
        $meta['newsletter_research'] = [
            'status' => 'research_skipped',
            'reason' => $reason,
        ];
        $storedEmail->processing_metadata_json = $meta;
    }

    /**
     * @param  list<array{url: string, result: array<string, mixed>}>  $youtubeRows
     */
    private function persistSuccessMetadata(
        ImportedEmail|CapturedInboundEmail $storedEmail,
        Thought $emailThought,
        Thought $research,
        array $youtubeRows,
        bool $degraded,
    ): void {
        $meta = $storedEmail->processing_metadata_json ?? [];
        $meta['newsletter_research'] = [
            'status' => $degraded ? 'research_partial' : 'research_completed',
            'youtube_transcripts' => $this->summarizeYoutubeRows($youtubeRows),
            'degraded' => $degraded,
            'research_thought_id' => $research->id,
            'email_thought_id' => $emailThought->id,
        ];
        $storedEmail->processing_metadata_json = $meta;
        $storedEmail->research_thought_id = $research->id;
        $storedEmail->save();

        $emailMeta = $emailThought->source_metadata ?? [];
        $emailMeta['research_thought_id'] = $research->id;
        $emailThought->source_metadata = $emailMeta;
        $emailThought->save();
    }

    /**
     * @param  list<array{url: string, result: array<string, mixed>}>  $youtubeRows
     * @return list<array<string, mixed>>
     */
    private function summarizeYoutubeRows(array $youtubeRows): array
    {
        $out = [];
        foreach ($youtubeRows as $row) {
            $r = $row['result'];
            $out[] = [
                'url' => $row['url'],
                'ok' => $r['ok'] ?? false,
                'reason' => $r['reason'] ?? null,
                'video_id' => $r['video_id'] ?? null,
            ];
        }

        return $out;
    }
}
