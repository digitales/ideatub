<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Best-effort public metadata via YouTube's oEmbed endpoint (no API key).
 *
 * @see https://oembed.com/providers.json
 */
final class YouTubeOEmbedService
{
    private const TIMEOUT_SECONDS = 6;

    /**
     * Adds {@see self::META_TITLE} and {@see self::META_AUTHOR_NAME} when missing and oEmbed succeeds.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function enrichVideoMetadataIfMissing(array $metadata, string $canonicalWatchUrl): array
    {
        if ($this->hasNonEmptyString($metadata, self::META_TITLE)) {
            return $metadata;
        }

        $snapshot = $this->fetchSnapshot($canonicalWatchUrl);
        if ($snapshot === null) {
            return $metadata;
        }

        return array_merge($metadata, array_filter($snapshot, fn ($v) => is_string($v) && trim($v) !== ''));
    }

    public const META_TITLE = 'video_title';

    public const META_AUTHOR_NAME = 'video_author_name';

    /**
     * @return array{video_title?: string, video_author_name?: string}|null
     */
    public function fetchSnapshot(string $canonicalWatchUrl): ?array
    {
        $url = trim($canonicalWatchUrl);
        if ($url === '' || ! str_starts_with($url, 'https://www.youtube.com/watch?v=')) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get('https://www.youtube.com/oembed', [
                    'format' => 'json',
                    'url' => $url,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();
        $title = $data['title'] ?? null;
        $author = $data['author_name'] ?? null;

        $out = [];
        if (is_string($title) && trim($title) !== '') {
            $out[self::META_TITLE] = trim($title);
        }
        if (is_string($author) && trim($author) !== '') {
            $out[self::META_AUTHOR_NAME] = trim($author);
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function hasNonEmptyString(array $metadata, string $key): bool
    {
        $v = $metadata[$key] ?? null;

        return is_string($v) && trim($v) !== '';
    }
}
