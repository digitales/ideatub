<?php

namespace Tests\Feature;

use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserMcpKey;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpCaptureArticleTest extends TestCase
{
    use RefreshDatabase;

    private function validKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('a', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return [$plain, $user];
    }

    private function mcpPost(string $key, array $data): TestResponse
    {
        return $this->postJson('/api/mcp', $data, ['x-ideatub-key' => $key]);
    }

    public function test_capture_article_creates_thought_and_queues_scrape(): void
    {
        Queue::fake();
        [$key, $user] = $this->validKeyAndUser();

        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
            $mock->shouldReceive('extractMetadata')->once()->andReturn([
                'type' => 'article',
                'tags' => ['article'],
                'people' => [],
                'action_items' => [],
            ]);
        });

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_article',
            'params' => ['url' => 'https://example.com/my-article'],
        ]);

        $response->assertStatus(200);
        $id = $response->json('result.id');
        $this->assertIsString($id);
        $response->assertJsonPath('result.status', 'queued');

        $thought = Thought::query()->whereKey($id)->first();
        $this->assertNotNull($thought);
        $this->assertSame('article', $thought->source);

        Queue::assertPushed(ScrapeArticleContent::class);
    }

    public function test_capture_article_requires_url(): void
    {
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_article',
            'params' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('error.code', -32602);
    }

    public function test_capture_article_appears_in_tools_list(): void
    {
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $response->assertStatus(200);
        $tools = $response->json('result.tools');
        $names = array_column($tools, 'name');
        $this->assertContains('capture_article', $names);
    }
}
