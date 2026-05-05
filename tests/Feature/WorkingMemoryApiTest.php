<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OAuthMcpJwtService;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_working_memory_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/thoughts/working-memory?scope_type=global&scope_key=global');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'unauthorized']);
    }

    #[Test]
    public function test_working_memory_returns_global_payload_for_authenticated_oauth_client(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';

        app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');

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
            ->getJson('/api/thoughts/working-memory?scope_type=global&scope_key=global');

        $response->assertOk();
        $response->assertJsonStructure([
            'scope_type',
            'scope_key',
            'freshness_state',
            'confidence_score',
            'summary_markdown',
            'key_concepts',
            'active_threads',
            'open_questions',
            'next_actions',
        ]);
        $response->assertJsonPath('scope_type', 'global');
        $response->assertJsonPath('scope_key', 'global');
    }

    #[Test]
    public function test_working_memory_requires_scope_type(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';

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
            ->getJson('/api/thoughts/working-memory?scope_key=global');

        $response->assertStatus(422);
        $response->assertJson(['error' => 'validation_error']);
        $this->assertNotEmpty($response->json('message'));
    }

    #[Test]
    public function test_working_memory_requires_scope_key(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';

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
            ->getJson('/api/thoughts/working-memory?scope_type=global');

        $response->assertStatus(422);
        $response->assertJson(['error' => 'validation_error']);
    }

    #[Test]
    public function test_working_memory_rejects_invalid_scope_type(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';

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
            ->getJson('/api/thoughts/working-memory?scope_type=workspace&scope_key=x');

        $response->assertStatus(422);
        $response->assertJson(['error' => 'validation_error']);
    }

    #[Test]
    public function test_working_memory_rejects_scope_key_longer_than_191(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';
        $longKey = str_repeat('a', 192);

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
            ->getJson('/api/thoughts/working-memory?scope_type=project&scope_key='.$longKey);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'validation_error']);
    }

    #[Test]
    public function test_working_memory_rejects_global_scope_with_non_global_scope_key(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';

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
            ->getJson('/api/thoughts/working-memory?scope_type=global&scope_key=other');

        $response->assertStatus(422);
        $response->assertJson(['error' => 'validation_error']);
    }
}
