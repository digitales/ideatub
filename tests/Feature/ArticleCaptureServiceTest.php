<?php

namespace Tests\Feature;

use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use App\Models\User;
use App\Services\ArticleCaptureService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleCaptureServiceTest extends TestCase
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

    public function test_capture_creates_root_thought_and_dispatches_scrape_job(): void
    {
        Queue::fake();
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $thought = $service->capture('https://example.com/test-article', [
            'user_id' => $user->id,
        ]);

        $this->assertInstanceOf(Thought::class, $thought);
        $this->assertSame('article', $thought->source);
        $this->assertSame('https://example.com/test-article', $thought->source_metadata['url']);
        $this->assertSame('queued', $thought->source_metadata['status']);
        $this->assertContains('article', $thought->metadata['tags'] ?? []);

        Queue::assertPushed(ScrapeArticleContent::class, function ($job) use ($thought) {
            return $job->thoughtId === $thought->id;
        });
    }

    public function test_capture_rejects_duplicate_url_for_same_user(): void
    {
        Queue::fake();
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $first = $service->capture('https://example.com/test-article', ['user_id' => $user->id]);
        $second = $service->capture('https://example.com/test-article', ['user_id' => $user->id]);

        $this->assertSame($first->id, $second->id);

        Queue::assertPushed(ScrapeArticleContent::class, 1);
    }

    public function test_capture_rejects_private_ip(): void
    {
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->capture('http://192.168.1.1/article', ['user_id' => $user->id]);
    }

    public function test_capture_rejects_non_http_scheme(): void
    {
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->capture('ftp://example.com/file', ['user_id' => $user->id]);
    }

    public function test_capture_applies_user_tags(): void
    {
        Queue::fake();
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $thought = $service->capture('https://example.com/tagged', [
            'user_id' => $user->id,
            'tags' => ['ai', 'coding'],
        ]);

        $tags = $thought->metadata['tags'] ?? [];
        $this->assertContains('article', $tags);
        $this->assertContains('ai', $tags);
        $this->assertContains('coding', $tags);
    }

    public function test_url_normalization_strips_tracking_params(): void
    {
        Queue::fake();
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $first = $service->capture('https://example.com/article?utm_source=twitter', ['user_id' => $user->id]);
        $second = $service->capture('https://example.com/article', ['user_id' => $user->id]);

        $this->assertSame($first->id, $second->id);
    }
}
