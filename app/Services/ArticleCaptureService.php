<?php

namespace App\Services;

use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use Illuminate\Support\Facades\DB;

class ArticleCaptureService
{
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref',
    ];

    public function __construct(
        private ThoughtCaptureService $captureService,
    ) {}

    /**
     * @param  array{user_id?: int, title?: string, tags?: list<string>, project?: string}  $options
     */
    public function capture(string $url, array $options = []): Thought
    {
        $url = trim($url);
        $this->validateUrl($url);

        $normalized = $this->normalizeUrl($url);
        $urlHash = hash('sha256', $normalized);

        $userId = (int) ($options['user_id'] ?? 0);
        if ($userId < 1) {
            throw new \InvalidArgumentException('user_id is required.');
        }

        $existing = Thought::query()
            ->where('user_id', $userId)
            ->where('source', 'article')
            ->whereNull('parent_id')
            ->whereJsonContains('source_metadata->url_hash', $urlHash)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $domain = parse_url($url, PHP_URL_HOST) ?: '';
        $userTags = $options['tags'] ?? [];
        $extraTags = array_merge(['article'], array_filter($userTags));

        $result = $this->captureService->create([
            'content' => "Capturing article: {$url}",
            'user_id' => $userId,
            'source' => 'article',
            'source_metadata' => [
                'url' => $url,
                'normalized_url' => $normalized,
                'url_hash' => $urlHash,
                'domain' => $domain,
                'status' => 'queued',
                'captured_at' => now()->toIso8601String(),
            ],
            'extra_tags' => $extraTags,
            'no_chunking' => true,
            'skip_ai_metadata' => false,
        ]);

        $thought = $result['thought'] ?? $result['root'];

        if (isset($options['title']) && $options['title'] !== '') {
            $sm = $thought->source_metadata ?? [];
            $sm['title_override'] = $options['title'];
            $thought->update(['source_metadata' => $sm]);
        }

        $thoughtId = $thought->id;
        DB::afterCommit(function () use ($thoughtId): void {
            ScrapeArticleContent::dispatch($thoughtId);
        });

        return $thought;
    }

    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only HTTP(S) URLs are supported.');
        }

        $host = $parsed['host'] ?? '';
        if ($host === '') {
            throw new \InvalidArgumentException('URL must have a valid host.');
        }

        if ($this->isPrivateHost($host)) {
            throw new \InvalidArgumentException('Private or localhost URLs are not allowed.');
        }
    }

    private function isPrivateHost(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            return true;
        }

        $ip = @inet_pton($host);
        if ($ip === false) {
            $resolved = @gethostbyname($host);
            if ($resolved === $host) {
                return false;
            }
            $ip = @inet_pton($resolved);
        }

        if ($ip === false) {
            return false;
        }

        $long = ip2long(inet_ntop($ip));
        if ($long === false) {
            return false;
        }

        return filter_var(inet_ntop($ip), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host'] ?? '');
        $path = rtrim($parsed['path'] ?? '/', '/');
        if ($path === '') {
            $path = '/';
        }

        $query = '';
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            parse_str($parsed['query'], $params);
            foreach (self::TRACKING_PARAMS as $param) {
                unset($params[$param]);
            }
            if ($params !== []) {
                ksort($params);
                $query = '?'.http_build_query($params);
            }
        }

        return "{$scheme}://{$host}{$path}{$query}";
    }
}
