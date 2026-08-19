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
        config(['features.job_search' => true]);
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $names = collect($response->json('result.tools'))->pluck('name')->all();
        foreach ([
            'add_prospect', 'score_prospect', 'promote_prospect', 'create_application',
            'update_application_stage', 'log_interaction', 'get_pipeline_status',
            'search_applications', 'add_achievement', 'retire_achievement', 'get_achievements',
            'generate_application_documents', 'export_application_pdf',
            'add_job_posting', 'add_application_research', 'update_application_outcome',
            'attach_application_document',
        ] as $name) {
            $this->assertContains($name, $names, "Missing tool: {$name}");
        }
    }

    #[Test]
    public function test_tools_list_excludes_job_search_tools_when_flag_disabled(): void
    {
        config(['features.job_search' => false]);
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $names = collect($response->json('result.tools'))->pluck('name')->all();
        foreach ([
            'add_prospect', 'score_prospect', 'promote_prospect', 'create_application',
            'update_application_stage', 'log_interaction', 'get_pipeline_status',
            'search_applications', 'add_achievement', 'retire_achievement', 'get_achievements',
            'generate_application_documents', 'export_application_pdf',
            'add_job_posting', 'add_application_research', 'update_application_outcome',
            'attach_application_document',
        ] as $name) {
            $this->assertNotContains($name, $names, "Tool should not be advertised: {$name}");
        }
    }

    #[Test]
    public function test_add_job_posting_sets_thought_and_is_idempotent_in_place(): void
    {
        config(['features.job_search' => true]);
        [$key, $user] = $this->validKeyAndUser();
        $application = \App\Models\Application::factory()->for($user)->create();

        $first = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'add_job_posting',
            'params' => ['application_id' => (string) $application->id, 'content' => 'Posting v1'],
        ]);
        $first->assertOk();
        $thoughtId = $first->json('result.data.job_posting_thought_id');
        $this->assertNotNull($thoughtId);
        $this->assertDatabaseHas('thoughts', ['id' => $thoughtId, 'content' => 'Posting v1', 'source' => 'job_search']);

        $second = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'add_job_posting',
            'params' => ['application_id' => (string) $application->id, 'content' => 'Posting v2'],
        ]);
        $second->assertOk();
        $this->assertSame($thoughtId, $second->json('result.data.job_posting_thought_id'));
        $this->assertDatabaseHas('thoughts', ['id' => $thoughtId, 'content' => 'Posting v2']);
        $this->assertDatabaseCount('thoughts', 1);
    }

    #[Test]
    public function test_add_application_research_sets_research_thought_and_is_idempotent_in_place(): void
    {
        config(['features.job_search' => true]);
        [$key, $user] = $this->validKeyAndUser();
        $application = \App\Models\Application::factory()->for($user)->create();

        $first = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'add_application_research',
            'params' => ['application_id' => (string) $application->id, 'content' => 'Brief v1'],
        ]);
        $first->assertOk();
        $thoughtId = $first->json('result.data.research_thought_id');
        $this->assertNotNull($thoughtId);

        $second = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'add_application_research',
            'params' => ['application_id' => (string) $application->id, 'content' => 'Brief v2'],
        ]);
        $second->assertOk();
        $this->assertSame($thoughtId, $second->json('result.data.research_thought_id'));
        $this->assertDatabaseHas('thoughts', ['id' => $thoughtId, 'content' => 'Brief v2']);
        $this->assertDatabaseCount('thoughts', 1);
    }

    #[Test]
    public function test_update_application_outcome_upserts_in_place(): void
    {
        config(['features.job_search' => true]);
        [$key, $user] = $this->validKeyAndUser();
        $application = \App\Models\Application::factory()->for($user)->create();

        $first = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'update_application_outcome',
            'params' => ['application_id' => (string) $application->id, 'content' => 'Screening booked'],
        ]);
        $first->assertOk();
        $thoughtId = $first->json('result.data.outcome_thought_id');
        $this->assertNotNull($thoughtId);

        $second = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'update_application_outcome',
            'params' => ['application_id' => (string) $application->id, 'content' => 'Rejected after onsite'],
        ]);
        $second->assertOk();
        $this->assertSame($thoughtId, $second->json('result.data.outcome_thought_id'));
        $this->assertDatabaseHas('thoughts', ['id' => $thoughtId, 'content' => 'Rejected after onsite']);
        $this->assertDatabaseCount('thoughts', 1);
    }

    #[Test]
    public function test_attach_application_document_stores_pdf_and_stamps_export_fields(): void
    {
        config(['features.job_search' => true]);
        \Illuminate\Support\Facades\Storage::fake('local');
        [$key, $user] = $this->validKeyAndUser();
        $application = \App\Models\Application::factory()->for($user)->create();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'attach_application_document',
            'params' => [
                'application_id' => (string) $application->id,
                'document' => 'cv',
                'base64_content' => base64_encode('%PDF-1.4 fake pdf bytes'),
            ],
        ]);

        $response->assertOk();
        $path = $response->json('result.data.path');
        $this->assertSame("job_pipeline/{$application->id}/cv.pdf", $path);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($path);

        $application->refresh();
        $this->assertNotNull($application->cv_pdf_path);
        $this->assertNotNull($application->cv_exported_at);
    }

    #[Test]
    public function test_attach_application_document_fails_when_flag_disabled(): void
    {
        config(['features.job_search' => false]);
        [$key, $user] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'attach_application_document',
            'params' => [
                'application_id' => (string) \Illuminate\Support\Str::uuid(),
                'document' => 'cv',
                'base64_content' => base64_encode('x'),
            ],
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('error'));
    }
}
