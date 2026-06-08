<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\UserMcpKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class McpUpdateProjectSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: User}
     */
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

    private function mcpPost(string $key, array $data): TestResponse
    {
        return $this->postJson('/api/mcp', $data, ['x-ideatub-key' => $key]);
    }

    #[Test]
    public function test_update_project_settings_toggles_working_memory_auto_update(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $project = Project::factory()->for($user)->create([
            'working_memory_auto_update' => true,
        ]);

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'update_project_settings',
            'params' => [
                'project_id' => (string) $project->id,
                'working_memory_auto_update' => false,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.data.working_memory_auto_update', false);
        $this->assertFalse($project->fresh()->working_memory_auto_update);
    }

    #[Test]
    public function test_get_mcp_includes_update_project_settings_method(): void
    {
        $response = $this->getJson('/api/mcp');
        $response->assertOk();

        $methods = $response->json('methods');
        $this->assertContains('update_project_settings', $methods);
    }

    #[Test]
    public function test_tools_list_includes_update_project_settings(): void
    {
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $response->assertOk();
        $names = collect($response->json('result.tools'))->pluck('name')->all();
        $this->assertContains('update_project_settings', $names);
    }
}
