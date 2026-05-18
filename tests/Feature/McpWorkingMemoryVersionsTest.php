<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMcpKey;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class McpWorkingMemoryVersionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: User}
     */
    private function createKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('v', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return [$plain, $user];
    }

    #[Test]
    public function test_list_working_memory_versions_returns_paginated_data_and_meta(): void
    {
        [$key, $user] = $this->createKeyAndUser();
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

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'list_working_memory_versions',
            'params' => [
                'scope_type' => 'project',
                'scope_key' => 'my-app',
            ],
            'id' => 1,
        ], ['x-ideatub-key' => $key]);

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('id', 1);
        $this->assertNull($response->json('error'));

        $result = $response->json('result');
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertCount(2, $result['data']);
        $this->assertEquals(2, $result['meta']['total']);
        $this->assertEquals((string) $newer->id, $result['data'][0]['id']);
        $this->assertEquals('external', $result['data'][0]['build_type']);
        $this->assertEquals('agent-sync', $result['data'][0]['source_label']);
    }

    #[Test]
    public function test_list_working_memory_versions_is_isolated_between_users(): void
    {
        [$key, $other] = $this->createKeyAndUser();
        $owner = User::factory()->create();
        $memory = WorkingMemory::factory()->for($owner)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create(['build_type' => 'consolidated']);

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'list_working_memory_versions',
            'params' => [
                'scope_type' => 'global',
                'scope_key' => 'global',
            ],
            'id' => 2,
        ], ['x-ideatub-key' => $key]);

        $response->assertOk();
        $this->assertNull($response->json('error'));
        $this->assertEquals(0, $response->json('result.meta.total'));
        $this->assertEquals([], $response->json('result.data'));
    }

    #[Test]
    public function test_list_working_memory_versions_requires_scope_params(): void
    {
        [$key] = $this->createKeyAndUser();

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'list_working_memory_versions',
            'params' => [],
            'id' => 3,
        ], ['x-ideatub-key' => $key]);

        $response->assertOk();
        $this->assertNotNull($response->json('error'));
    }

    #[Test]
    public function test_list_working_memory_versions_requires_auth(): void
    {
        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'list_working_memory_versions',
            'params' => [
                'scope_type' => 'global',
                'scope_key' => 'global',
            ],
            'id' => 4,
        ]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function test_get_working_memory_version_returns_detail_payload(): void
    {
        [$key, $user] = $this->createKeyAndUser();
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
            'confidence_score' => 0.88,
        ]);

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'get_working_memory_version',
            'params' => [
                'version_id' => (string) $version->id,
            ],
            'id' => 5,
        ], ['x-ideatub-key' => $key]);

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $this->assertNull($response->json('error'));

        $result = $response->json('result');
        $this->assertEquals((string) $version->id, $result['id']);
        $this->assertEquals('# Historical snapshot', $result['summary_markdown']);
        $this->assertEquals('Ship it', $result['structured_sections']['Goals'][0]['text']);
        $this->assertEquals(0.88, $result['confidence_score']);
    }

    #[Test]
    public function test_get_working_memory_version_not_found_for_other_users_version(): void
    {
        [$key] = $this->createKeyAndUser();
        $owner = User::factory()->create();
        $memory = WorkingMemory::factory()->for($owner)->create();
        $version = WorkingMemoryVersion::factory()->for($memory)->create();

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'get_working_memory_version',
            'params' => [
                'version_id' => (string) $version->id,
            ],
            'id' => 6,
        ], ['x-ideatub-key' => $key]);

        $response->assertOk();
        $this->assertNotNull($response->json('error'));
    }

    #[Test]
    public function test_get_working_memory_version_requires_version_id(): void
    {
        [$key] = $this->createKeyAndUser();

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'get_working_memory_version',
            'params' => [],
            'id' => 7,
        ], ['x-ideatub-key' => $key]);

        $response->assertOk();
        $this->assertNotNull($response->json('error'));
    }

    #[Test]
    public function test_get_working_memory_version_requires_auth(): void
    {
        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'get_working_memory_version',
            'params' => [
                'version_id' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
            ],
            'id' => 8,
        ]);

        $response->assertUnauthorized();
    }
}
