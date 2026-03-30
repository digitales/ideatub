<?php

namespace App\Services\LinkSummary;

class NewsletterEditorialLinkCandidateBuilder
{
    private const UNCATEGORIZED_LABEL = 'Uncategorized editorial links';

    /**
     * @param  list<array<string, mixed>|string>  $links
     * @return list<array{
     *     original_url: string,
     *     normalized_url: string,
     *     normalized_url_hash: string,
     *     classification: string,
     *     newsletter_section_label: string,
     *     newsletter_section_order: int,
     *     source_excerpt: string
     * }>
     */
    public function build(string $bodyText, array $links): array
    {
        $body = str_replace(["\r\n", "\r"], "\n", $bodyText);
        $lines = $body === '' ? [] : explode("\n", $body);
        $lineStarts = $this->lineStartOffsets($lines);
        $headingLineIndices = [];
        $headingMarkers = [];
        $headingOrderByLabel = [];
        $nextOrder = 0;

        foreach ($lines as $i => $line) {
            $trim = trim($line);
            if ($trim === '' || ! $this->isSectionHeading($trim)) {
                continue;
            }
            $headingLineIndices[] = $i;
            if (! isset($headingOrderByLabel[$trim])) {
                $nextOrder++;
                $headingOrderByLabel[$trim] = $nextOrder;
            }
            $headingMarkers[] = [
                'offset' => $lineStarts[$i],
                'label' => $trim,
                'order' => $headingOrderByLabel[$trim],
            ];
        }

        $sponsorIntervals = $this->buildSponsorIntervals($body, $lines, $lineStarts, $headingLineIndices);

        $seenHashes = [];
        $out = [];

        foreach ($links as $item) {
            $originalUrl = is_string($item) ? $item : (isset($item['url']) && is_string($item['url']) ? $item['url'] : null);
            if ($originalUrl === null || trim($originalUrl) === '') {
                continue;
            }

            $originalUrl = $this->trimTrailingPunctuation($originalUrl);
            $normalized = $this->normalizeHttpUrl($originalUrl);
            if ($normalized === null) {
                continue;
            }

            $hash = sha1($normalized);
            if (isset($seenHashes[$hash])) {
                continue;
            }

            if ($this->isNoiseUrl($normalized)) {
                continue;
            }

            $pos = $this->findUrlPosition($body, $originalUrl, $normalized);
            if ($pos === null) {
                continue;
            }

            $classification = $this->isInsideSponsorIntervals($pos, $sponsorIntervals) ? 'sponsor' : 'editorial';

            [$sectionLabel, $sectionOrder] = $this->sectionAtPosition($pos, $headingMarkers);

            $excerpt = $this->buildSourceExcerpt($body, $lines, $lineStarts, $pos);

            $seenHashes[$hash] = true;
            $out[] = [
                'original_url' => $originalUrl,
                'normalized_url' => $normalized,
                'normalized_url_hash' => $hash,
                'classification' => $classification,
                'newsletter_section_label' => $sectionLabel,
                'newsletter_section_order' => $sectionOrder,
                'source_excerpt' => $excerpt,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $lines
     * @return list<int>
     */
    private function lineStartOffsets(array $lines): array
    {
        $starts = [];
        $acc = 0;
        foreach ($lines as $i => $line) {
            $starts[$i] = $acc;
            $acc += strlen($line) + 1;
        }

        return $starts;
    }

    private function isSectionHeading(string $trim): bool
    {
        if (strlen($trim) < 4) {
            return false;
        }
        if (preg_match('#https?://#i', $trim)) {
            return false;
        }
        if (! preg_match('/[A-Za-z]/', $trim)) {
            return false;
        }
        if (preg_match('/[a-z]/', $trim)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<int>  $headingLineIndices
     * @return list<array{0: int, 1: int}>
     */
    private function buildSponsorIntervals(string $body, array $lines, array $lineStarts, array $headingLineIndices): array
    {
        $headingSet = array_fill_keys($headingLineIndices, true);
        $n = count($lines);
        $intervals = [];

        for ($i = 0; $i < $n; $i++) {
            if (stripos($lines[$i], 'TOGETHER WITH') === false) {
                continue;
            }

            $start = $lineStarts[$i];
            $endLine = $n;
            $sawSponsorMarker = false;

            for ($j = $i; $j < $n; $j++) {
                $trimmed = trim($lines[$j]);

                if ($trimmed !== '' && $this->isSponsorMarkedLine($trimmed)) {
                    $sawSponsorMarker = true;
                }

                if ($j > $i && isset($headingSet[$j]) && $this->isSectionHeading($trimmed)) {
                    $endLine = $j;
                    break;
                }

                if (
                    $sawSponsorMarker
                    && $trimmed === ''
                    && ($nextBlockStart = $this->nextNonEmptyLineIndex($lines, $j + 1)) !== null
                ) {
                    $nextBlockLine = trim($lines[$nextBlockStart]);

                    if (
                        ! $this->isSponsorMarkedLine($nextBlockLine)
                        || (isset($headingSet[$nextBlockStart]) && $this->isSectionHeading($nextBlockLine))
                    ) {
                        $endLine = $nextBlockStart;
                        break;
                    }
                }
            }

            $endPos = $endLine < $n ? $lineStarts[$endLine] : strlen($body);
            $chunk = substr($body, $start, max(0, $endPos - $start));
            if (! $sawSponsorMarker || stripos($chunk, '(SPONSOR)') === false) {
                continue;
            }
            $intervals[] = [$start, $endPos];
        }

        return $intervals;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private function isInsideSponsorIntervals(int $pos, array $intervals): bool
    {
        foreach ($intervals as [$a, $b]) {
            if ($pos >= $a && $pos < $b) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{offset: int, label: string, order: int}>  $headingMarkers
     * @return array{0: string, 1: int}
     */
    private function sectionAtPosition(int $pos, array $headingMarkers): array
    {
        $current = null;
        foreach ($headingMarkers as $h) {
            if ($h['offset'] <= $pos) {
                $current = $h;
            } else {
                break;
            }
        }

        if ($current === null) {
            return [self::UNCATEGORIZED_LABEL, 0];
        }

        return [$current['label'], $current['order']];
    }

    /**
     * @param  list<string>  $lines
     * @param  list<int>  $lineStarts
     */
    private function buildSourceExcerpt(string $body, array $lines, array $lineStarts, int $pos): string
    {
        if ($lines === []) {
            return '';
        }

        $lineIndex = $this->lineIndexAtPosition($body, $lines, $lineStarts, $pos);
        $lineCount = count($lines);
        $start = $lineIndex;
        $end = $lineIndex;

        while ($start > 0) {
            $candidate = trim($lines[$start - 1]);
            if ($candidate === '' || $this->isSectionHeading($candidate)) {
                break;
            }
            $start--;
        }

        while ($end + 1 < $lineCount) {
            $candidate = trim($lines[$end + 1]);
            if ($candidate === '' || $this->isSectionHeading($candidate)) {
                break;
            }
            $end++;
        }

        $parts = [];
        for ($i = $start; $i <= $end; $i++) {
            $trimmed = trim($lines[$i]);
            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }

        $excerpt = trim(implode(' ', array_filter($parts, fn (string $p) => $p !== '')));

        return $excerpt !== '' ? mb_substr($excerpt, 0, 2000) : '';
    }

    /**
     * @param  list<string>  $lines
     * @param  list<int>  $lineStarts
     */
    private function lineIndexAtPosition(string $body, array $lines, array $lineStarts, int $pos): int
    {
        $lineCount = count($lines);
        $lineIndex = 0;

        for ($i = 0; $i < $lineCount; $i++) {
            $nextStart = $lineStarts[$i + 1] ?? strlen($body);
            if ($pos >= $lineStarts[$i] && $pos < $nextStart) {
                return $i;
            }

            $lineIndex = $i;
        }

        return $lineIndex;
    }

    private function findUrlPosition(string $body, string $originalUrl, string $normalized): ?int
    {
        $pos = strpos($body, $originalUrl);
        if ($pos !== false) {
            return $pos;
        }

        $pos = strpos($body, $normalized);
        if ($pos !== false) {
            return $pos;
        }

        $parts = parse_url($normalized);
        if (is_array($parts) && isset($parts['host'], $parts['path'])) {
            $needle = $parts['host'].$parts['path'];
            $pos = stripos($body, $needle);
            if ($pos !== false) {
                return $pos;
            }
        }

        return null;
    }

    private function isNoiseUrl(string $normalized): bool
    {
        $parts = parse_url($normalized);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return true;
        }

        $host = strtolower($parts['host']);
        $path = strtolower($parts['path'] ?? '');

        if (preg_match('/(^|\.)(linkedin|twitter)\.com$/', $host) === 1) {
            return true;
        }

        if (preg_match('/(^|\.)x\.com$/', $host) === 1) {
            return true;
        }

        if (preg_match('/(^|\.)(advertise|marketing)(\.|$)/', $host) === 1) {
            return true;
        }

        if (preg_match('#/(unsubscribe|manage|advertise|jobs)(?:/|$|\?)#i', $path) === 1) {
            return true;
        }

        if (preg_match('#/(refer|reward|rewards)(?:/|$|\?)#i', $path) === 1) {
            return true;
        }

        if (preg_match('#/(account|accounts|account-management|billing|preferences)(?:/|$|\?|-)#i', $path) === 1) {
            return true;
        }

        return false;
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

    /**
     * @param  list<string>  $lines
     */
    private function nextNonEmptyLineIndex(array $lines, int $start): ?int
    {
        $lineCount = count($lines);

        for ($i = $start; $i < $lineCount; $i++) {
            if (trim($lines[$i]) !== '') {
                return $i;
            }
        }

        return null;
    }

    private function isSponsorMarkedLine(string $line): bool
    {
        return stripos($line, 'TOGETHER WITH') !== false
            || stripos($line, '(SPONSOR)') !== false;
    }
}
