<?php

namespace Tests\Feature;

use App\Jobs\ClassifyArticleLinks;
use App\Jobs\ProcessThoughtLinkSummary;
use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleCapturePipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

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

    public function test_full_pipeline_scrape_then_classify(): void
    {
        Queue::fake([ScrapeArticleContent::class, ClassifyArticleLinks::class, ProcessThoughtLinkSummary::class]);
        $this->mockOpenRouter();

        $html = file_get_contents(base_path('tests/fixtures/articles/blog-post.html'));
        Http::fake(['example.com/*' => Http::response($html, 200)]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/articles', [
            'url' => 'https://example.com/full-pipeline-test',
        ]);

        $response->assertRedirect('/articles');

        $root = Thought::query()
            ->where('user_id', $user->id)
            ->where('source', 'article')
            ->whereNull('parent_id')
            ->first();

        $this->assertNotNull($root);
        $this->assertSame('queued', $root->source_metadata['status']);

        $scrapeJob = new ScrapeArticleContent($root->id);
        app()->call([$scrapeJob, 'handle']);

        $root->refresh();
        $this->assertSame('scraped', $root->source_metadata['status']);

        $child = Thought::query()
            ->where('parent_id', $root->id)
            ->first();
        $this->assertNotNull($child);
        $this->assertStringContainsString('first paragraph', $child->content);
    }
}
