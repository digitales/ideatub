<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\OAuthMcpJwtService;
use App\Services\OpenRouterService;
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

    public function test_recent_excludes_hidden_email_thoughts(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';

        $visible = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Recent visible',
            'source' => 'web',
        ]);
        $hidden = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Recent hidden email',
            'source' => 'email',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

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
            ->getJson('/api/thoughts/recent?limit=10');

        $response->assertStatus(200);
        $ids = array_column($response->json('thoughts'), 'id');
        $this->assertNotContains($hidden->id, $ids);
        $this->assertContains($visible->id, $ids);
    }

    public function test_search_excludes_hidden_email_thoughts(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';
        $embedding = array_fill(0, 1536, 0.03);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'apihiddensearchtoken body',
            'source' => 'email',
            'embedding' => $embedding,
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('apihiddensearchtoken')->andReturn($embedding);
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
            ->getJson('/api/thoughts/search?query=apihiddensearchtoken');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('thoughts'));
    }

    public function test_stats_excludes_hidden_email_from_count(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Counted visible',
            'source' => 'web',
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Uncounted hidden email',
            'source' => 'email',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

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
            ->getJson('/api/thoughts/stats');

        $response->assertStatus(200);
        $response->assertJson(['count' => 1]);
    }
}
