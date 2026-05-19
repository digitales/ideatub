<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMcpKey;
use App\Models\WorkingMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class McpUpsertWorkingMemoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: User}
     */
    private function createKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('u', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return [$plain, $user];
    }

    #[Test]
    public function test_upsert_working_memory_via_mcp_persists_external_version(): void
    {
        [$key, $user] = $this->createKeyAndUser();
        $projectId = (string) Str::uuid();

        $markdown = "## Current Focus\n\n- Ship the fix.\n\n## Active Priorities\n\n- Test staging.";

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'upsert_working_memory',
            'params' => [
                'scope_type' => 'project',
                'scope_key' => $projectId,
                'content' => $markdown,
                'source_label' => 'elixirr-sync',
            ],
            'id' => 1,
        ], ['x-ideatub-key' => $key]);

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('id', 1);
        $this->assertNull($response->json('error'));

        $result = $response->json('result');
        $this->assertEquals('external', $result['build_type']);
        $this->assertEquals('project', $result['scope_type']);
        $this->assertEquals($projectId, $result['scope_key']);

        $memory = WorkingMemory::where('user_id', $user->id)
            ->where('scope_type', 'project')
            ->where('scope_key', $projectId)
            ->first();
        $this->assertNotNull($memory);
        $this->assertEquals('fresh', $memory->freshness_state);
    }

    #[Test]
    public function test_upsert_working_memory_elixirr_sync_rejects_slug_scope_key(): void
    {
        [$key] = $this->createKeyAndUser();

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'upsert_working_memory',
            'params' => [
                'scope_type' => 'project',
                'scope_key' => 'dezeen',
                'content' => "## Current Focus\n\n- Ship the fix.\n\n## Active Priorities\n\n- Test staging.",
                'source_label' => 'elixirr-sync',
            ],
            'id' => 10,
        ], ['x-ideatub-key' => $key]);

        $response->assertOk();
        $response->assertJsonPath('error.code', -32602);
        $this->assertStringContainsString(
            'UUID',
            (string) $response->json('error.message'),
        );
    }

    #[Test]
    public function test_upsert_working_memory_requires_content(): void
    {
        [$key] = $this->createKeyAndUser();

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'upsert_working_memory',
            'params' => [
                'scope_type' => 'project',
                'scope_key' => 'dezeen',
            ],
            'id' => 2,
        ], ['x-ideatub-key' => $key]);

        $response->assertOk();
        $this->assertNotNull($response->json('error'));
    }

    #[Test]
    public function test_upsert_working_memory_requires_auth(): void
    {
        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'upsert_working_memory',
            'params' => [
                'scope_type' => 'project',
                'scope_key' => 'dezeen',
                'content' => "## Current Focus\n\n- Test.",
            ],
            'id' => 3,
        ]);

        $response->assertUnauthorized();
    }
}
