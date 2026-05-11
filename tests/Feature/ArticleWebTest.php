<?php

namespace Tests\Feature;

use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_articles_index_requires_auth(): void
    {
        $this->get('/articles')->assertRedirect('/login');
    }

    public function test_articles_index_shows_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/articles')->assertStatus(200);
    }

    public function test_articles_index_lists_captured_articles(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'article',
            'parent_id' => null,
            'content' => 'Test Article Title',
            'source_metadata' => [
                'url' => 'https://example.com/article',
                'domain' => 'example.com',
                'status' => 'complete',
                'title' => 'Test Article Title',
            ],
        ]);

        $response = $this->actingAs($user)->get('/articles');
        $response->assertSee('Test Article Title');
        $response->assertSee('example.com');
    }

    public function test_store_captures_article_and_redirects(): void
    {
        Queue::fake();

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

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/articles', [
            'url' => 'https://example.com/new-article',
        ]);

        $response->assertRedirect('/articles');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('thoughts', [
            'user_id' => $user->id,
            'source' => 'article',
        ]);

        Queue::assertPushed(ScrapeArticleContent::class);
    }

    public function test_store_validates_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/articles', [
            'url' => 'not-a-url',
        ]);

        $response->assertSessionHasErrors('url');
    }
}
