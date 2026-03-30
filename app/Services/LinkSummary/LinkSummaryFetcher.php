<?php

namespace App\Services\LinkSummary;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LinkSummaryFetcher
{
    private const MAX_REDIRECTS = 5;

    /**
     * Fetch a URL and extract lightweight text signals for summarization.
     *
     * @return array{
     *     status_code: int,
     *     normalized_url: string,
     *     title: string,
     *     visible_text: string,
     *     content_fingerprint: string
     * }
     *
     * `normalized_url` is the effective URL after redirects when the client reports one; otherwise the requested URL.
     *
     * @throws ConnectionException When the HTTP client cannot complete the request (network, DNS, timeout).
     */
    public function fetch(string $url): array
    {
        [$response, $normalizedUrl] = $this->fetchFollowingSafeRedirects($url);

        $html = $response->body();
        $title = $this->extractTitle($html);
        $visibleText = $this->extractVisibleText($html);
        $fingerprint = hash('sha256', $visibleText);

        return [
            'status_code' => $response->status(),
            'normalized_url' => $normalizedUrl,
            'title' => $title,
            'visible_text' => $visibleText,
            'content_fingerprint' => $fingerprint,
        ];
    }

    /**
     * @return array{0: Response, 1: string}
     */
    private function fetchFollowingSafeRedirects(string $url): array
    {
        $currentUrl = $url;

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $this->guardAgainstUnsafeUrl($currentUrl);

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; IdeaTubLinkSummary/1.0)',
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            ])
                ->timeout(30)
                ->connectTimeout(10)
                ->withOptions(['allow_redirects' => false])
                ->get($currentUrl);

            if (! $response->redirect()) {
                return [$response, $currentUrl];
            }

            $location = $response->header('Location');
            if (! is_string($location) || trim($location) === '') {
                return [$response, $currentUrl];
            }

            $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
        }

        throw new \InvalidArgumentException('Too many redirects.');
    }

    private function guardAgainstUnsafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new \InvalidArgumentException('Invalid URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Unsupported URL scheme.');
        }

        $host = strtolower(trim(rtrim((string) ($parts['host'] ?? ''), '.'), '[]'));
        if ($host === '') {
            throw new \InvalidArgumentException('Invalid URL host.');
        }

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            throw new \InvalidArgumentException('Unsafe URL host.');
        }

        if ($this->isSuspiciousNumericHostForm($host)) {
            throw new \InvalidArgumentException('Unsafe URL host.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $publicIp = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            if ($publicIp === false) {
                throw new \InvalidArgumentException('Unsafe URL host.');
            }
        }
    }

    private function isSuspiciousNumericHostForm(string $host): bool
    {
        if (preg_match('/^\d+$/', $host) === 1) {
            return true;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            $isNumericLike = preg_match('/^(?:\d+|0x[0-9a-f]+)$/i', $label) === 1;

            if (! $isNumericLike) {
                return false;
            }
        }

        return true;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $resolved = UriResolver::resolve(new Uri($baseUrl), new Uri($location));

        return (string) $resolved;
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $raw = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return Str::squish($raw);
        }

        return '';
    }

    private function extractVisibleText(string $html): string
    {
        $without = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $without = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $without) ?? $without;
        $without = preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', '', $without) ?? $without;

        $text = html_entity_decode(strip_tags($without), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::squish($text);
    }
}
