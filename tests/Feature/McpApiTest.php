<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMcpKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpApiTest extends TestCase
{
    use RefreshDatabase;

    private function validKey(): string
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('a', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return $plain;
    }

    public function test_get_mcp_returns_server_info(): void
    {
        $response = $this->getJson('/api/mcp');

        $response->assertStatus(200);
        $response->assertJson([
            'name' => 'ideatub',
            'version' => '1.0',
            'protocol' => 'json-rpc',
            'methods' => ['search_thoughts', 'browse_recent', 'thought_stats', 'capture_thought'],
        ]);
    }

    public function test_post_initialize_returns_capabilities(): void
    {
        $key = $this->validKey();

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.serverInfo.name', 'ideatub');
        $response->assertJsonStructure(['result' => ['capabilities' => ['tools']]]);
    }

    public function test_post_tools_list_returns_tools(): void
    {
        $key = $this->validKey();

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.tools.0.name', 'search_thoughts');
        $response->assertJsonPath('result.tools.1.name', 'browse_recent');
        $response->assertJsonPath('result.tools.2.name', 'thought_stats');
        $response->assertJsonPath('result.tools.3.name', 'capture_thought');
    }

    public function test_post_without_key_returns_401(): void
    {
        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);

        $response->assertStatus(401);
    }
}
