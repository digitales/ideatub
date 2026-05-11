<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\Article\ArticleContentExtractor;
use App\Services\ThoughtCaptureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeArticleContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 300;

    public function __construct(
        public readonly string $thoughtId,
    ) {}

    public function handle(
        ArticleContentExtractor $extractor,
        ThoughtCaptureService $captureService,
    ): void {
        $root = Thought::query()->find($this->thoughtId);
        if ($root === null) {
            return;
        }

        $this->updateStatus($root, 'scraping');

        $url = $root->source_metadata['url'] ?? '';
        if ($url === '') {
            $this->markFailed($root, 'No URL in source_metadata');

            return;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; IdeaTubArticle/1.0)',
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            ])
                ->timeout(30)
                ->connectTimeout(10)
                ->get($url);

            if ($response->failed()) {
                $this->markFailed($root, "HTTP {$response->status()}");

                return;
            }

            $html = $response->body();
        } catch (\Throwable $e) {
            $this->markFailed($root, $e->getMessage());
            throw $e;
        }

        $extracted = $extractor->extract($html, $url);

        if (trim($extracted['body_text']) === '') {
            $this->markFailed($root, 'No content extracted');

            return;
        }

        $contentWithCopyright = $extracted['body_text'];
        if ($extracted['copyright'] !== null) {
            $contentWithCopyright .= "\n\n---\n\u{00a9} ".$extracted['copyright']."\nSource: {$url}";
        }

        $captureService->create([
            'content' => $contentWithCopyright,
            'user_id' => (int) $root->user_id,
            'parent_id' => $root->id,
            'source' => 'article',
            'source_metadata' => [
                'child_type' => 'full_text',
                'url' => $url,
                'title' => $extracted['title'],
                'author' => $extracted['author'],
                'published_date' => $extracted['published_date'],
                'copyright' => $extracted['copyright'],
                'domain' => $root->source_metadata['domain'] ?? '',
            ],
            'no_chunking' => false,
            'skip_ai_metadata' => false,
        ]);

        $title = $root->source_metadata['title_override'] ?? $extracted['title'] ?: $url;
        $byLine = collect([$extracted['author'], $extracted['published_date']])->filter()->implode(' | ');
        $rootContent = $title."\n\n".$url;
        if ($byLine !== '') {
            $rootContent .= "\nBy ".$byLine;
        }

        $sm = $root->source_metadata ?? [];
        $sm['status'] = 'scraped';
        $sm['title'] = $extracted['title'];
        $sm['author'] = $extracted['author'];
        $sm['published_date'] = $extracted['published_date'];
        $sm['copyright'] = $extracted['copyright'];
        $sm['description'] = $extracted['description'];
        $sm['link_count'] = count($extracted['links']);

        $root->update([
            'content' => $rootContent,
            'source_metadata' => $sm,
        ]);

        $links = $extracted['links'];
        DB::afterCommit(function () use ($links): void {
            ClassifyArticleLinks::dispatch($this->thoughtId, $links);
        });
    }

    private function updateStatus(Thought $root, string $status): void
    {
        $sm = $root->source_metadata ?? [];
        $sm['status'] = $status;
        $root->update(['source_metadata' => $sm]);
    }

    private function markFailed(Thought $root, string $error): void
    {
        $sm = $root->source_metadata ?? [];
        $sm['status'] = 'scrape_failed';
        $sm['error'] = mb_substr($error, 0, 255);
        $root->update(['source_metadata' => $sm]);
    }
}
