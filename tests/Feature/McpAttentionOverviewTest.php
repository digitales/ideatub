<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMcpKey;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpAttentionOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function validKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('p', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return [$plain, $user];
    }

    private function mcpPost(string $key, array $data): TestResponse
    {
        return $this->postJson('/api/mcp', $data, ['x-ideatub-key' => $key]);
    }

    public function test_get_mcp_lists_get_attention_overview_when_feature_enabled(): void
    {
        config(['features.attention_pulse' => true]);

        $response = $this->getJson('/api/mcp');

        $response->assertOk();
        $this->assertContains('get_attention_overview', $response->json('methods'));
    }

    public function test_get_attention_overview_tool_schema_properties_is_object_not_array(): void
    {
        config(['features.attention_pulse' => true]);
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $response->assertOk();
        $tool = collect($response->json('result.tools'))->firstWhere('name', 'get_attention_overview');
        $this->assertIsArray($tool);
        $this->assertSame('object', data_get($tool, 'inputSchema.type'));
        $this->assertStringContainsString(
            '"name":"get_attention_overview","description"',
            preg_replace('/\s+/', '', $response->getContent())
        );
        $this->assertMatchesRegularExpression(
            '/"name":"get_attention_overview"[^}]*"inputSchema":\{"type":"object","properties":\{\}\}/',
            preg_replace('/\s+/', '', $response->getContent())
        );
    }

    public function test_get_mcp_omits_get_attention_overview_when_feature_disabled(): void
    {
        config(['features.attention_pulse' => false]);

        $response = $this->getJson('/api/mcp');

        $response->assertOk();
        $this->assertNotContains('get_attention_overview', $response->json('methods'));
    }

    public function test_get_attention_overview_returns_sections_when_signals_exist(): void
    {
        config([
            'features.attention_pulse' => true,
            'features.working_memory_ui' => true,
        ]);

        [$key, $user] = $this->validKeyAndUser();

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
            'last_refreshed_at' => now(),
            'freshness_state' => 'fresh',
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'authoring_status' => 'fallback',
            'build_type' => 'consolidated',
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'get_attention_overview',
            'params' => [],
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'result' => [
                'total_count',
                'sections' => [
                    [
                        'key',
                        'title',
                        'description',
                        'items' => [
                            [
                                'kind',
                                'title',
                                'href',
                                'meta',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $this->assertGreaterThan(0, $response->json('result.total_count'));
        $this->assertSame('memory_health', $response->json('result.sections.0.key'));
    }

    public function test_get_attention_overview_returns_empty_sections_when_no_signals(): void
    {
        config(['features.attention_pulse' => true]);
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'get_attention_overview',
            'params' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.total_count', 0);
        $response->assertJsonPath('result.sections', []);
    }

    public function test_get_attention_overview_not_available_when_feature_disabled(): void
    {
        config(['features.attention_pulse' => false]);
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'get_attention_overview',
            'params' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('error.code', -32601);
    }
}
