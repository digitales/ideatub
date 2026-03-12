<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserMcpKey;
use App\Services\OpenRouterService;
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

    private function validKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('x', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return [$plain, $user];
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

    public function test_capture_thought_without_source_stores_mcp(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_thought',
            'params' => ['content' => 'A thought from MCP'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.id', fn ($id) => is_string($id) && strlen($id) > 0);
        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('mcp', $thought->source);
    }

    public function test_capture_thought_with_source_stores_client_source(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_thought',
            'params' => [
                'content' => 'A thought from Claude',
                'source' => 'claude',
            ],
        ]);

        $response->assertStatus(200);
        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('claude', $thought->source);
    }

    public function test_browse_recent_includes_source_and_source_metadata(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Thought with source',
            'source' => 'chatgpt',
            'source_metadata' => ['client_version' => '1.0'],
        ]);

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'browse_recent',
            'params' => ['limit' => 5],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.thoughts.0.source', 'chatgpt');
        $response->assertJsonPath('result.thoughts.0.source_metadata.client_version', '1.0');
    }
}
