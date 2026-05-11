<?php

namespace Tests\Feature\Import;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MarkdownImportEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.file_upload', true);
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.0));
        $mock->shouldReceive('extractMetadata')->andReturn([]);
        $this->app->instance(OpenRouterService::class, $mock);
    }

    public function test_imports_single_file_as_thought(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'thought',
                'files' => [
                    ['title' => 'My Note', 'content' => '# Hello from markdown'],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'imported');
        $response->assertJsonPath('imported.0.title', 'My Note');
        $response->assertJsonPath('imported.0.status', 'success');
        $response->assertJsonCount(0, 'failed');

        $this->assertDatabaseHas('project_thought', [
            'project_id' => $project->id,
        ]);
    }

    public function test_imports_multiple_files(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'research',
                'files' => [
                    ['title' => 'File One', 'content' => '# Research one'],
                    ['title' => 'File Two', 'content' => '# Research two'],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'imported');
        $this->assertEquals(2, $project->thoughts()->count());
    }

    public function test_rejects_invalid_type(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'invalid_type',
                'files' => [
                    ['title' => 'Note', 'content' => '# Hello'],
                ],
            ]);

        $response->assertUnprocessable();
    }

    public function test_rejects_empty_files_array(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'thought',
                'files' => [],
            ]);

        $response->assertUnprocessable();
    }

    public function test_rejects_missing_title(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'thought',
                'files' => [
                    ['content' => '# Hello'],
                ],
            ]);

        $response->assertUnprocessable();
    }

    public function test_requires_authentication(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->postJson(route('projects.import-markdown', $project), [
            'type' => 'thought',
            'files' => [
                ['title' => 'Note', 'content' => '# Hello'],
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_returns_404_when_feature_disabled(): void
    {
        config()->set('features.file_upload', false);
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'thought',
                'files' => [
                    ['title' => 'Note', 'content' => '# Hello'],
                ],
            ]);

        $response->assertNotFound();
    }

    public function test_forbids_importing_to_other_users_project(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::create(['user_id' => $owner->id, 'title' => 'Test']);

        $response = $this->actingAs($other)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'thought',
                'files' => [
                    ['title' => 'Note', 'content' => '# Hello'],
                ],
            ]);

        $response->assertForbidden();
    }
}
