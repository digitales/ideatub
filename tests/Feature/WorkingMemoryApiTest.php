<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\OAuthMcpJwtService;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
            'structured_sections',
            'references',
            'section_references',
            'citation_coverage',
            'build_diagnostics',
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
        $this->assertIsArray($response->json('structured_sections'));
        $this->assertIsArray($response->json('references'));
        $this->assertIsArray($response->json('section_references'));
        $isSupportedReferenceUrl = static function (string $candidate): bool {
            $parts = parse_url($candidate);
            if ($parts === false) {
                return false;
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($scheme !== '') {
                if (! in_array($scheme, ['http', 'https'], true)) {
                    return false;
                }

                return trim((string) ($parts['host'] ?? '')) !== '';
            }

            if (str_starts_with($candidate, '//')) {
                return false;
            }

            $path = (string) ($parts['path'] ?? '');
            foreach (explode('/', $path) as $segment) {
                if ($segment === '..') {
                    return false;
                }
            }

            return true;
        };
        $sectionReferences = $response->json('section_references');
        foreach ($sectionReferences as $section => $references) {
            $this->assertIsArray($references);
            foreach ($references as $reference) {
                $this->assertIsArray($reference);
                $url = (string) ($reference['url'] ?? '');
                $this->assertNotSame('', $url);
                if (($reference['type'] ?? null) === 'stream_filter') {
                    $this->assertStringContainsString('section=', $url);
                    $this->assertStringContainsString(rawurlencode((string) $section), $url);
                } else {
                    $this->assertTrue($isSupportedReferenceUrl($url));
                }
                $this->assertStringNotContainsString('javascript:', strtolower($url));
            }
        }
        $citationCoverage = $response->json('citation_coverage');
        $this->assertTrue($citationCoverage === null || is_float($citationCoverage));
        $buildDiagnostics = $response->json('build_diagnostics');
        $this->assertTrue($buildDiagnostics === null || is_array($buildDiagnostics));
        if (is_array($buildDiagnostics)) {
            $this->assertArrayHasKey('required_items', $buildDiagnostics);
            $this->assertArrayHasKey('cited_items', $buildDiagnostics);
            $this->assertArrayHasKey('reason_codes', $buildDiagnostics);
            $this->assertIsInt($buildDiagnostics['required_items']);
            $this->assertIsInt($buildDiagnostics['cited_items']);
            $this->assertIsArray($buildDiagnostics['reason_codes']);
        }
        if (! empty($response->json('references'))) {
            $this->assertNotNull($citationCoverage);
        }
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
    public function test_working_memory_returns_tag_payload_for_matching_tagged_thought(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Tagged thought for AI scope.',
            'metadata' => ['tags' => ['ai']],
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
            ->getJson('/api/thoughts/working-memory?scope_type=tag&scope_key=ai');

        $response->assertOk();
        $response->assertJsonPath('scope_type', 'tag');
        $response->assertJsonPath('scope_key', 'ai');
        $this->assertGreaterThanOrEqual(1, (int) $response->json('input_count'));
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

    #[Test]
    public function test_upsert_working_memory_via_rest_persists_external_version(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';
        $projectId = (string) Str::uuid();

        $this->mock(OAuthMcpJwtService::class, function ($mock) use ($user, $token): void {
            $mock->shouldReceive('verifyAccessToken')
                ->once()
                ->with($token)
                ->andReturn([
                    'user_id' => $user->id,
                    'aud' => config('oauth-mcp.resource_api'),
                ]);
        });

        $markdown = "## Current Focus\n\n- Ship the fix.\n\n## Active Priorities\n\n- Test staging.";

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/thoughts/working-memory/upsert', [
                'scope_type' => 'project',
                'scope_key' => $projectId,
                'content' => $markdown,
                'source_label' => 'elixirr-sync',
            ]);

        $response->assertOk();
        $response->assertJsonPath('build_type', 'external');
        $response->assertJsonPath('scope_type', 'project');
        $response->assertJsonPath('scope_key', $projectId);
    }

    #[Test]
    public function test_upsert_working_memory_elixirr_sync_rejects_slug_scope_key(): void
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
            ->postJson('/api/thoughts/working-memory/upsert', [
                'scope_type' => 'project',
                'scope_key' => 'dezeen',
                'content' => "## Current Focus\n\n- Ship the fix.\n\n## Active Priorities\n\n- Test staging.",
                'source_label' => 'elixirr-sync',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'validation_error');
        $this->assertStringContainsString(
            'UUID',
            (string) $response->json('message'),
        );
    }

    #[Test]
    public function test_working_memory_get_returns_canonical_metadata_after_upsert_with_source_label(): void
    {
        $user = User::factory()->create();
        $token = 'test-access-token';
        $projectId = (string) Str::uuid();
        $markdown = "## Current Focus\n\n- Ship the fix.\n\n## Active Priorities\n\n- Test staging.";

        $this->mock(OAuthMcpJwtService::class, function ($mock) use ($user, $token): void {
            $mock->shouldReceive('verifyAccessToken')
                ->twice()
                ->with($token)
                ->andReturn([
                    'user_id' => $user->id,
                    'aud' => config('oauth-mcp.resource_api'),
                ]);
        });

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/thoughts/working-memory/upsert', [
                'scope_type' => 'project',
                'scope_key' => $projectId,
                'content' => $markdown,
                'source_label' => 'elixirr-sync',
            ])
            ->assertOk();

        $version = WorkingMemoryVersion::query()
            ->whereHas('workingMemory', fn ($q) => $q
                ->where('user_id', $user->id)
                ->where('scope_type', 'project')
                ->where('scope_key', $projectId))
            ->where('build_type', 'external')
            ->first();

        $this->assertNotNull($version);
        $this->assertNotNull($version->created_at);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/thoughts/working-memory?scope_type=project&scope_key='.$projectId);

        $response->assertOk();
        $response->assertJsonPath('canonical_version_id', (string) $version->id);
        $response->assertJsonPath('canonical_created_at', $version->created_at->toIso8601String());
        $response->assertJsonPath('source_label', 'elixirr-sync');
    }

    #[Test]
    public function test_upsert_working_memory_rest_requires_auth(): void
    {
        $response = $this->postJson('/api/thoughts/working-memory/upsert', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'content' => "## Current Focus\n\n- Test.",
        ]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function test_upsert_working_memory_rest_validates_content(): void
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
            ->postJson('/api/thoughts/working-memory/upsert', [
                'scope_type' => 'project',
                'scope_key' => 'dezeen',
            ]);

        $response->assertUnprocessable();
    }

    #[Test]
    public function test_working_memory_legacy_lists_include_derived_urls_for_heuristic_build(): void
    {
        config([
            'features.working_memory_ai_authored' => false,
            'working_memory.authoring_enabled' => false,
        ]);

        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Heuristic capture without a question mark.',
        ]);

        app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');

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
            ->getJson('/api/thoughts/working-memory?scope_type=global&scope_key=global');

        $response->assertOk();
        $threads = $response->json('active_threads');
        $this->assertIsArray($threads);
        $first = $threads[0];
        $this->assertSame((string) $thought->id, $first['thought_id']);
        $this->assertArrayHasKey('url', $first);
        $this->assertStringContainsString('/thoughts/', (string) $first['url']);
    }
}
