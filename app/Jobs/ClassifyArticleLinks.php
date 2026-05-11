<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Services\ResearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClassifyArticleLinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    private const SOCIAL_DOMAINS = [
        'twitter.com', 'x.com', 'facebook.com', 'linkedin.com',
        'reddit.com', 'pinterest.com', 'instagram.com', 'tiktok.com',
        'threads.net', 'mastodon.social',
    ];

    private const SOCIAL_PATH_PREFIXES = [
        '/intent/', '/sharer/', '/share', '/shareArticle',
    ];

    private const NOISE_PATHS = [
        '/about', '/contact', '/privacy', '/terms', '/login',
        '/register', '/signup', '/subscribe', '/feed', '/rss',
    ];

    private const MEDIA_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'mp4',
        'mp3', 'wav', 'pdf', 'zip', 'tar', 'gz',
    ];

    /**
     * @param  list<array{url: string, anchor_text: string}>  $links
     */
    public function __construct(
        public readonly string $thoughtId,
        public readonly array $links,
    ) {}

    public function handle(ResearchService $researchService): void
    {
        $root = Thought::query()->find($this->thoughtId);
        if ($root === null) {
            return;
        }

        $this->updateStatus($root, 'links_processing');

        $articleUrl = $root->source_metadata['url'] ?? '';
        $articleDomain = $root->source_metadata['domain'] ?? '';

        $editorialLinks = array_filter($this->links, function (array $link) use ($articleUrl, $articleDomain): bool {
            return $this->isEditorial($link['url'], $articleUrl, $articleDomain);
        });

        $editorialCount = 0;
        foreach ($editorialLinks as $link) {
            $normalized = strtolower($link['url']);
            $hash = hash('sha256', $normalized);

            $existing = ThoughtLinkSummary::query()
                ->where('source_thought_id', $root->id)
                ->where('normalized_url_hash', $hash)
                ->first();

            if ($existing !== null) {
                continue;
            }

            $row = ThoughtLinkSummary::query()->create([
                'user_id' => (int) $root->user_id,
                'source_thought_id' => $root->id,
                'source_type' => 'article',
                'original_url' => $link['url'],
                'normalized_url' => $normalized,
                'normalized_url_hash' => $hash,
                'classification' => 'article_reference',
                'processing_status' => 'queued',
                'source_excerpt' => mb_substr($link['anchor_text'], 0, 500),
            ]);

            ProcessThoughtLinkSummary::dispatch($row->id);
            $editorialCount++;
        }

        try {
            $researchService->queueResearchRunForIdea($root, 'article');
        } catch (\Throwable $e) {
            Log::warning('Article research queue failed', [
                'thought_id' => $root->id,
                'error' => $e->getMessage(),
            ]);
        }

        $sm = $root->source_metadata ?? [];
        $sm['status'] = 'complete';
        $sm['editorial_link_count'] = $editorialCount;
        $root->update(['source_metadata' => $sm]);
    }

    public function failed(\Throwable $exception): void
    {
        $root = Thought::query()->find($this->thoughtId);
        if ($root !== null) {
            $sm = $root->source_metadata ?? [];
            $sm['status'] = 'links_failed';
            $sm['error'] = mb_substr($exception->getMessage(), 0, 255);
            $root->update(['source_metadata' => $sm]);
        }
    }

    private function updateStatus(Thought $root, string $status): void
    {
        $sm = $root->source_metadata ?? [];
        $sm['status'] = $status;
        $root->update(['source_metadata' => $sm]);
    }

    private function isEditorial(string $url, string $articleUrl, string $articleDomain): bool
    {
        if (strcasecmp($url, $articleUrl) === 0) {
            return false;
        }

        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        $path = strtolower($parsed['path'] ?? '');

        foreach (self::SOCIAL_DOMAINS as $social) {
            if ($host === $social || str_ends_with($host, '.'.$social)) {
                return false;
            }
        }

        foreach (self::SOCIAL_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        if ($host === $articleDomain || str_ends_with($host, '.'.$articleDomain)) {
            foreach (self::NOISE_PATHS as $noise) {
                if ($path === $noise || str_starts_with($path, $noise.'/')) {
                    return false;
                }
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, self::MEDIA_EXTENSIONS, true)) {
            return false;
        }

        return true;
    }
}
