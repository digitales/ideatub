<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\OAuthMcpJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_thoughts_search_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/thoughts/search?query=test');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'unauthorized']);
    }

    public function test_thoughts_recent_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/thoughts/recent');

        $response->assertStatus(401);
    }

    public function test_thoughts_stats_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/thoughts/stats');

        $response->assertStatus(401);
    }

    public function test_thoughts_store_without_auth_returns_401(): void
    {
        $response = $this->postJson('/api/thoughts', ['content' => 'A thought']);

        $response->assertStatus(401);
    }

    public function test_thoughts_search_with_invalid_bearer_returns_401(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson('/api/thoughts/search?query=test');

        $response->assertStatus(401);
    }

    public function test_thoughts_search_with_tag_query_returns_tagged_thought_first(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.01);
        $token = 'test-access-token';

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Decision about project spec',
            'embedding' => $embedding,
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('decision:project-spec')->andReturn($embedding);
        });

        $this->mock(OAuthMcpJwtService::class, function ($mock) use ($user, $token): void {
            $mock->shouldReceive('verifyAccessToken')
                ->once()
                ->with($token)
                ->andReturn([
                    'user_id' => $user->id,
                    'aud' => config('oauth-mcp.resource_api'),
                ]);
        });

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/thoughts/search?query=decision:project-spec');

        $response->assertStatus(200);
        $result = $response->json();
        $this->assertArrayHasKey('thoughts', $result);
        $thoughtIds = array_column($result['thoughts'], 'id');
        $this->assertContains($thought->id, $thoughtIds);
        $this->assertSame($thought->id, $result['thoughts'][0]['id'], 'Tag-matched thought should be first');
    }
}
