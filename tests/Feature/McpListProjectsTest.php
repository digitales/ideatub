<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\UserMcpKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class McpListProjectsTest extends TestCase
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
    public function test_list_projects_via_mcp(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list_projects',
            'params' => ['elixirr_client_slug' => 'dezeen'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.data.0.elixirr_client_slug', 'dezeen');
    }

    #[Test]
    public function test_list_projects_filters_by_parent_project_id(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        Project::factory()->elixirrChild($root, 'foo')->for($user)->create();
        Project::factory()->elixirrChild($root, 'bar')->for($user)->create();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list_projects',
            'params' => ['parent_project_id' => (string) $root->id],
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'result.data');
        $response->assertJsonPath('result.data.0.parent_project_id', (string) $root->id);
    }

    #[Test]
    public function test_list_projects_rejects_invalid_parent_project_id(): void
    {
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'list_projects',
            'params' => ['parent_project_id' => 'not-a-uuid'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('error.code', -32602);
    }

    #[Test]
    public function test_get_mcp_includes_list_projects_method(): void
    {
        $response = $this->getJson('/api/mcp');

        $response->assertOk();
        $methods = $response->json('methods');
        $this->assertIsArray($methods);
        $this->assertContains('list_projects', $methods);
    }

    #[Test]
    public function test_tools_list_includes_list_projects(): void
    {
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $response->assertOk();
        $names = array_column($response->json('result.tools'), 'name');
        $this->assertContains('list_projects', $names);
    }
}
