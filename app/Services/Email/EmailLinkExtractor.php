<?php

namespace App\Services\Email;

class EmailLinkExtractor
{
    /**
     * @return list<array{url: string, type: string}>
     */
    public function extractFromContent(string $plainText, ?string $html = null): array
    {
        $raw = [];
        if ($plainText !== '') {
            $raw = array_merge($raw, $this->collectUrlsFromText($plainText));
        }
        if ($html !== null && trim($html) !== '') {
            $raw = array_merge($raw, $this->collectUrlsFromHtml($html));
        }

        $out = [];
        $seen = [];

        foreach ($raw as $candidate) {
            $trimmed = $this->trimTrailingPunctuation($candidate);
            if ($trimmed === '') {
                continue;
            }

            $videoId = $this->extractYouTubeVideoId($trimmed);
            if ($videoId !== null) {
                $key = 'yt:'.$videoId;
                $row = [
                    'url' => 'https://www.youtube.com/watch?v='.$videoId,
                    'type' => 'youtube',
                ];
            } else {
                $normalized = $this->normalizeHttpUrl($trimmed);
                if ($normalized === null) {
                    continue;
                }
                $key = 'url:'.$normalized;
                $row = ['url' => $normalized, 'type' => 'generic'];
            }

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return list<array{url: string, type: string}>
     */
    public function linksFromProcessingMetadata(?array $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        $links = $metadata['extracted_links'] ?? null;
        if (! is_array($links)) {
            return [];
        }

        $out = [];
        foreach ($links as $row) {
            if (is_array($row) && isset($row['url'], $row['type']) && is_string($row['url']) && is_string($row['type'])) {
                $out[] = ['url' => $row['url'], 'type' => $row['type']];
            }
        }

        return $out;
    }

    public function extractYouTubeVideoId(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('#[?&]v=([a-zA-Z0-9_-]{11})(?:[^a-zA-Z0-9_-]|$)#', $url, $m)) {
            return $m[1];
        }

        if (preg_match('#(?:^|//)(?:www\.)?youtu\.be/([a-zA-Z0-9_-]{11})(?:[^a-zA-Z0-9_-]|$)#i', $url, $m)) {
            return $m[1];
        }

        if (preg_match('#youtube\.com/(?:embed|shorts|live)/([a-zA-Z0-9_-]{11})(?:[^a-zA-Z0-9_-]|$)#i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function collectUrlsFromText(string $text): array
    {
        preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches);

        return $matches[0] ?? [];
    }

    /**
     * @return list<string>
     */
    private function collectUrlsFromHtml(string $html): array
    {
        $urls = [];

        if (preg_match_all('#href\s*=\s*"([^"]+)"#i', $html, $double)) {
            foreach ($double[1] as $href) {
                if (preg_match('#^https?://#i', $href)) {
                    $urls[] = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        if (preg_match_all("#href\s*=\s*'([^']+)'#i", $html, $single)) {
            foreach ($single[1] as $href) {
                if (preg_match('#^https?://#i', $href)) {
                    $urls[] = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        return array_merge($urls, $this->collectUrlsFromText($html));
    }

    private function trimTrailingPunctuation(string $url): string
    {
        return rtrim($url, ".,;:!?)'\"\x0a\x0d\t");
    }

    private function normalizeHttpUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#'.$parts['fragment'] : '';
        $portSuffix = '';

        if (is_int($port) && ! $this->isDefaultPort($scheme, $port)) {
            $portSuffix = ':'.$port;
        }

        return $scheme.'://'.$host.$portSuffix.$path.$query.$fragment;
    }

    private function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443);
    }
}
