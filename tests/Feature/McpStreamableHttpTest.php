<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMcpKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class McpStreamableHttpTest extends TestCase
{
    use RefreshDatabase;

    private const STREAMABLE_ACCEPT = 'application/json, text/event-stream';

    private function validKey(): string
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('b', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return $plain;
    }

    private function streamableHeaders(string $key, ?string $sessionId = null): array
    {
        $h = [
            'Accept' => self::STREAMABLE_ACCEPT,
            'x-ideatub-key' => $key,
        ];
        if ($sessionId !== null) {
            $h['Mcp-Session-Id'] = $sessionId;
        }

        return $h;
    }

    public function test_streamable_initialize_returns_session_header(): void
    {
        $key = $this->validKey();

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ], $this->streamableHeaders($key));

        $response->assertStatus(200);
        $response->assertHeader('Mcp-Session-Id');
        $response->assertJsonPath('result.protocolVersion', '2025-03-26');
        $sid = $response->headers->get('Mcp-Session-Id');
        $this->assertIsString($sid);
        $this->assertNotSame('', $sid);
    }

    public function test_streamable_tools_list_requires_session(): void
    {
        $key = $this->validKey();

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => (object) [],
        ], $this->streamableHeaders($key));

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Mcp-Session-Id required');
    }

    public function test_streamable_initialized_notification_returns_202(): void
    {
        $key = $this->validKey();

        $init = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ], $this->streamableHeaders($key));

        $init->assertStatus(200);
        $sessionId = $init->headers->get('Mcp-Session-Id');
        $this->assertNotNull($sessionId);

        $note = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], $this->streamableHeaders($key, $sessionId));

        $note->assertStatus(202);
        $note->assertContent('');
    }

    public function test_streamable_tools_list_with_session_succeeds(): void
    {
        $key = $this->validKey();

        $init = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ], $this->streamableHeaders($key));

        $sessionId = $init->headers->get('Mcp-Session-Id');
        $this->assertNotNull($sessionId);

        $list = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => (object) [],
        ], $this->streamableHeaders($key, $sessionId));

        $list->assertStatus(200);
        $list->assertJsonStructure(['result' => ['tools']]);
    }

    public function test_get_with_event_stream_accept_returns_405(): void
    {
        $response = $this->get('/api/mcp', ['Accept' => 'text/event-stream']);

        $response->assertStatus(405);
        $response->assertHeader('Allow');
    }

    public function test_legacy_post_json_without_event_stream_unchanged(): void
    {
        $key = $this->validKey();

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ], ['x-ideatub-key' => $key]);

        $response->assertStatus(200);
        $response->assertHeaderMissing('Mcp-Session-Id');
    }

    public function test_delete_session_terminates_and_follow_up_fails(): void
    {
        $key = $this->validKey();

        $init = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ], $this->streamableHeaders($key));

        $sessionId = $init->headers->get('Mcp-Session-Id');
        $this->assertNotNull($sessionId);

        $del = $this->delete('/api/mcp', [], [
            'x-ideatub-key' => $key,
            'Mcp-Session-Id' => $sessionId,
        ]);
        $del->assertStatus(204);

        $list = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => (object) [],
        ], $this->streamableHeaders($key, $sessionId));

        $list->assertStatus(404);
    }

    public function test_streamable_rejects_unknown_origin(): void
    {
        $key = $this->validKey();

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ], array_merge($this->streamableHeaders($key), [
            'Origin' => 'https://evil.example',
        ]));

        $response->assertStatus(403);
    }

    public function test_cache_driver_stores_session(): void
    {
        $key = $this->validKey();

        $init = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ], $this->streamableHeaders($key));

        $sessionId = $init->headers->get('Mcp-Session-Id');
        $this->assertNotNull($sessionId);
        $this->assertTrue(Cache::has('mcp:session:'.$sessionId));
    }
}
