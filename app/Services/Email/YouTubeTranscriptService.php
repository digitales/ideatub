<?php

namespace App\Services\Email;

use MrMySQL\YoutubeTranscript\Exception\FailedToCreateConsentCookieException;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptAvailableException;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptFoundException;
use MrMySQL\YoutubeTranscript\Exception\PoTokenRequiredException;
use MrMySQL\YoutubeTranscript\Exception\TooManyRequestsException;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\Exception\YouTubeRequestFailedException;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;
use Psr\Log\LoggerInterface;
use Throwable;

class YouTubeTranscriptService
{
    public function __construct(
        private readonly TranscriptListFetcher $transcriptListFetcher,
        private readonly EmailLinkExtractor $emailLinkExtractor,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return array{
     *     ok: true,
     *     video_id: string,
     *     language_code: string,
     *     transcript: string
     * }|array{
     *     ok: false,
     *     reason: string,
     *     video_id: string|null,
     *     detail?: string
     * }
     */
    public function fetchForUrl(string $url): array
    {
        $videoId = $this->emailLinkExtractor->extractYouTubeVideoId($url);
        if ($videoId === null) {
            return [
                'ok' => false,
                'reason' => 'unsupported_youtube_url',
                'video_id' => null,
            ];
        }

        try {
            $list = $this->transcriptListFetcher->fetch($videoId);

            try {
                $transcript = $list->findTranscript(['en']);
            } catch (NoTranscriptFoundException) {
                $transcript = $list->findGeneratedTranscript(['en']);
            }

            $segments = $transcript->fetch();
            $text = trim(implode(' ', array_map(
                fn (array $s) => trim((string) ($s['text'] ?? '')),
                $segments
            )));
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return [
                'ok' => true,
                'video_id' => $videoId,
                'language_code' => $transcript->language_code,
                'transcript' => $text,
            ];
        } catch (TranscriptsDisabledException|NoTranscriptAvailableException|NoTranscriptFoundException $e) {
            return $this->failurePayload($videoId, 'transcript_unavailable', $e);
        } catch (TooManyRequestsException $e) {
            return $this->failurePayload($videoId, 'youtube_rate_limited', $e);
        } catch (PoTokenRequiredException $e) {
            return $this->failurePayload($videoId, 'youtube_po_token_required', $e);
        } catch (FailedToCreateConsentCookieException|YouTubeRequestFailedException $e) {
            return $this->failurePayload($videoId, 'youtube_fetch_failed', $e);
        } catch (Throwable $e) {
            return $this->failurePayload($videoId, 'youtube_fetch_failed', $e);
        }
    }

    /**
     * @return array{ok: false, reason: string, video_id: string|null, detail?: string}
     */
    private function failurePayload(string $videoId, string $reason, Throwable $e): array
    {
        $this->logger?->info('youtube_transcript.fetch_failed', [
            'video_id' => $videoId,
            'reason' => $reason,
            'exception' => $e::class,
        ]);

        return [
            'ok' => false,
            'reason' => $reason,
            'video_id' => $videoId,
            'detail' => $e->getMessage(),
        ];
    }
}
