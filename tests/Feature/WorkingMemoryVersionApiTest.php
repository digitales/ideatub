<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\OAuthMcpJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryVersionApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_versions_list_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/thoughts/working-memory/versions?scope_type=global&scope_key=global');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'unauthorized']);
    }

    #[Test]
    public function test_version_show_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/thoughts/working-memory/versions/019e0705-5591-73e9-be2e-0fb9c86b269a');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'unauthorized']);
    }

    #[Test]
    public function test_versions_list_returns_paginated_items_for_scope(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => 'my-app',
        ]);

        $newer = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'confidence_score' => 0.9,
            'citation_coverage' => 0.75,
            'build_diagnostics_json' => ['source_label' => 'agent-sync'],
            'created_at' => now()->subHour(),
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'consolidated',
            'created_at' => now()->subDay(),
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'incremental',
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
            ->getJson('/api/thoughts/working-memory/versions?scope_type=project&scope_key=my-app');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'created_at',
                    'build_type',
                    'authoring_status',
                    'confidence_score',
                    'source_label',
                    'citation_coverage',
                ],
            ],
            'meta' => [
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);
        $response->assertJsonPath('meta.total', 2);
        $response->assertJsonPath('data.0.id', (string) $newer->id);
        $response->assertJsonPath('data.0.build_type', 'external');
        $response->assertJsonPath('data.0.source_label', 'agent-sync');
    }

    #[Test]
    public function test_versions_list_is_isolated_between_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = 'test-access-token';
        $memory = WorkingMemory::factory()->for($owner)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create(['build_type' => 'consolidated']);

        $this->mock(OAuthMcpJwtService::class, function ($mock) use ($other, $token): void {
            $mock->shouldReceive('verifyAccessToken')
                ->once()
                ->with($token)
                ->andReturn([
                    'user_id' => $other->id,
                    'aud' => config('oauth-mcp.resource_api'),
                ]);
        });

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/thoughts/working-memory/versions?scope_type=global&scope_key=global');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 0);
        $response->assertJsonPath('data', []);
    }

    #[Test]
    public function test_version_show_returns_detail_payload_for_owner(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => 'my-app',
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'summary_markdown' => '# Historical snapshot',
            'structured_sections_json' => ['Goals' => [['text' => 'Ship it']]],
            'section_references_json' => ['Goals' => []],
            'references_json' => [['type' => 'url', 'url' => 'https://example.com', 'label' => 'Example']],
            'key_concepts_json' => [['title' => 'Concept']],
            'active_threads_json' => [['title' => 'Thread']],
            'open_questions_json' => [['question' => 'Why?']],
            'next_actions_json' => [['action' => 'Do it']],
            'confidence_score' => 0.88,
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
            ->getJson('/api/thoughts/working-memory/versions/'.$version->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'id',
            'created_at',
            'build_type',
            'authoring_status',
            'confidence_score',
            'source_label',
            'citation_coverage',
            'summary_markdown',
            'structured_sections',
            'section_references',
            'references',
            'key_concepts',
            'active_threads',
            'open_questions',
            'next_actions',
            'validation_error',
            'build_diagnostics',
            'source_window_start',
            'source_window_end',
        ]);
        $response->assertJsonPath('id', (string) $version->id);
        $response->assertJsonPath('summary_markdown', '# Historical snapshot');
        $response->assertJsonPath('structured_sections.Goals.0.text', 'Ship it');
        $response->assertJsonPath('confidence_score', 0.88);
    }

    #[Test]
    public function test_version_show_returns_404_for_other_users_version(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = 'test-access-token';
        $memory = WorkingMemory::factory()->for($owner)->create();
        $version = WorkingMemoryVersion::factory()->for($memory)->create();

        $this->mock(OAuthMcpJwtService::class, function ($mock) use ($other, $token): void {
            $mock->shouldReceive('verifyAccessToken')
                ->once()
                ->with($token)
                ->andReturn([
                    'user_id' => $other->id,
                    'aud' => config('oauth-mcp.resource_api'),
                ]);
        });

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/thoughts/working-memory/versions/'.$version->id);

        $response->assertNotFound();
    }
}
