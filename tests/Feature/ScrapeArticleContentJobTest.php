<?php

namespace Tests\Feature;

use App\Jobs\ClassifyArticleLinks;
use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScrapeArticleContentJobTest extends TestCase
{
    use RefreshDatabase;

    private function mockOpenRouter(): void
    {
        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->andReturn($embed);
            $mock->shouldReceive('extractMetadata')->andReturn([
                'type' => 'article',
                'tags' => ['article'],
                'people' => [],
                'action_items' => [],
            ]);
        });
    }

    private function createRootArticleThought(User $user, string $url = 'https://example.com/article'): Thought
    {
        return Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'article',
            'content' => "Capturing article: {$url}",
            'source_metadata' => [
                'url' => $url,
                'domain' => 'example.com',
                'status' => 'queued',
                'url_hash' => hash('sha256', $url),
            ],
        ]);
    }

    public function test_scrape_creates_full_text_child_and_dispatches_classify(): void
    {
        Queue::fake([ClassifyArticleLinks::class]);
        $this->mockOpenRouter();

        $html = file_get_contents(base_path('tests/fixtures/articles/blog-post.html'));
        Http::fake(['example.com/*' => Http::response($html, 200)]);

        $user = User::factory()->create();
        $root = $this->createRootArticleThought($user);

        $job = new ScrapeArticleContent($root->id);
        app()->call([$job, 'handle']);

        $root->refresh();
        $this->assertSame('scraped', $root->source_metadata['status']);
        $this->assertStringContainsString('OG Test Article Title', $root->content);

        $child = Thought::query()
            ->where('parent_id', $root->id)
            ->where('source', 'article')
            ->first();

        $this->assertNotNull($child);
        $this->assertStringContainsString('first paragraph', $child->content);
        $this->assertStringContainsString('2026 Jane Doe', $child->content);
        $this->assertSame('full_text', $child->source_metadata['child_type']);

        Queue::assertPushed(ClassifyArticleLinks::class);
    }

    public function test_scrape_sets_failed_status_on_http_error(): void
    {
        Queue::fake();
        Http::fake(['example.com/*' => Http::response('Not Found', 404)]);

        $user = User::factory()->create();
        $root = $this->createRootArticleThought($user);

        $job = new ScrapeArticleContent($root->id);

        try {
            app()->call([$job, 'handle']);
        } catch (\Throwable) {
        }

        $root->refresh();
        $this->assertSame('scrape_failed', $root->source_metadata['status']);
    }

    public function test_scrape_sets_failed_status_on_empty_content(): void
    {
        Queue::fake();
        $this->mockOpenRouter();
        Http::fake(['example.com/*' => Http::response('<html><body></body></html>', 200)]);

        $user = User::factory()->create();
        $root = $this->createRootArticleThought($user);

        $job = new ScrapeArticleContent($root->id);
        app()->call([$job, 'handle']);

        $root->refresh();
        $this->assertSame('scrape_failed', $root->source_metadata['status']);
    }
}
