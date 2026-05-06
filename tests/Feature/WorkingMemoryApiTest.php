<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
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
            'last_refreshed_at',
            'effective_consolidation_window_days',
            'baseline_build_type',
            'overlay_deltas',
            'input_count',
        ]);
        $response->assertJsonPath('scope_type', 'global');
        $response->assertJsonPath('scope_key', 'global');
        $this->assertIsInt($response->json('effective_consolidation_window_days'));
        $this->assertContains($response->json('baseline_build_type'), ['consolidated', 'incremental']);
        $this->assertIsArray($response->json('overlay_deltas'));
        $this->assertIsInt($response->json('input_count'));
    }

    #[Test]
    public function test_working_memory_normalizes_project_scope_key_case(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Scoped project note for normalization.',
            'source_metadata' => ['project' => 'my-app'],
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
            ->getJson('/api/thoughts/working-memory?scope_type=project&scope_key=MY-APP');

        $response->assertOk();
        $response->assertJsonPath('scope_type', 'project');
        $response->assertJsonPath('scope_key', 'my-app');
        $response->assertJsonPath('freshness_state', 'fresh');
    }

    #[Test]
    public function test_working_memory_persists_snapshot_on_first_read_without_prior_build(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Seed thought so consolidated snapshot has corpus signal.',
            'metadata' => ['tags' => ['seed']],
        ]);

        WorkingMemory::query()->where('user_id', $user->id)->delete();

        $this->assertDatabaseCount('working_memory_versions', 0);

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
        $this->assertDatabaseCount('working_memory_versions', 1);
        $response->assertJsonPath('freshness_state', 'fresh');
        $this->assertStringContainsString('## Executive summary', (string) $response->json('summary_markdown'));
    }

    #[Test]
    public function test_working_memory_trims_scope_query_parameters_before_validation(): void
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

        $query = http_build_query([
            'scope_type' => '  global  ',
            'scope_key' => '  global  ',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/thoughts/working-memory?'.$query);

        $response->assertOk();
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

    #[Test]
    public function test_working_memory_returns_insights_payload(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Research body line',
            'metadata' => ['type' => 'research', 'tags' => ['t1']],
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
            ->getJson('/api/thoughts/working-memory?scope_type=insights&scope_key=global');

        $response->assertOk();
        $response->assertJsonPath('scope_type', 'insights');
        $response->assertJsonPath('scope_key', 'global');
        $this->assertStringContainsString('# Memory insights', (string) $response->json('summary_markdown'));
    }
}
