<?php

namespace Tests\Feature\Mcp;

use App\Models\JobProspect;
use App\Models\User;
use App\Models\UserMcpKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobSearchMcpTest extends TestCase
{
    use RefreshDatabase;

    private function validKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('x', 32);
        UserMcpKey::query()->create(['user_id' => $user->id, 'key_hash' => UserMcpKey::hashKey($plain)]);

        return [$plain, $user];
    }

    private function mcpPost(string $key, array $data): TestResponse
    {
        return $this->postJson('/api/mcp', $data, ['x-ideatub-key' => $key]);
    }

    #[Test]
    public function test_add_prospect_creates_a_prospect_when_flag_enabled(): void
    {
        config(['features.job_search' => true]);
        [$key, $user] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'add_prospect',
            'params' => ['company' => 'Acme Ltd', 'role_title' => 'Staff Engineer', 'source' => 'linkedin'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('job_prospects', [
            'user_id' => $user->id, 'company' => 'Acme Ltd', 'status' => 'new',
        ]);
    }

    #[Test]
    public function test_add_prospect_fails_when_flag_disabled(): void
    {
        config(['features.job_search' => false]);
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'add_prospect',
            'params' => ['company' => 'Acme Ltd', 'role_title' => 'Staff Engineer', 'source' => 'linkedin'],
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('error'));
    }

    #[Test]
    public function test_promote_prospect_returns_application_id(): void
    {
        config(['features.job_search' => true]);
        [$key, $user] = $this->validKeyAndUser();
        $prospect = JobProspect::factory()->for($user)->create();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'promote_prospect',
            'params' => ['prospect_id' => (string) $prospect->id],
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('result.data.application_id'));
    }

    #[Test]
    public function test_get_pipeline_status_groups_by_stage(): void
    {
        config(['features.job_search' => true]);
        [$key, $user] = $this->validKeyAndUser();
        \App\Models\Application::factory()->for($user)->create(['stage' => 'applied']);

        $response = $this->mcpPost($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'get_pipeline_status']);

        $response->assertOk();
        $this->assertArrayHasKey('applied', $response->json('result.data.applications'));
    }

    #[Test]
    public function test_tools_list_includes_all_job_search_tools(): void
    {
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $names = collect($response->json('result.tools'))->pluck('name')->all();
        foreach ([
            'add_prospect', 'score_prospect', 'promote_prospect', 'create_application',
            'update_application_stage', 'log_interaction', 'get_pipeline_status',
            'search_applications', 'add_achievement', 'retire_achievement', 'get_achievements',
            'generate_application_documents', 'export_application_pdf',
        ] as $name) {
            $this->assertContains($name, $names, "Missing tool: {$name}");
        }
    }
}
