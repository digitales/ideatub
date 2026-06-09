<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectIndexDemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_demo_mode_obfuscates_projects_index_without_mutating_records(): void
    {
        config(['services.demo_mode.enabled' => true]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'title' => 'PROJECT_INDEX_DEMO_TITLE_SECRET',
            'description' => 'PROJECT_INDEX_DEMO_DESC_SECRET',
        ]);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'feat-seed-project-index-demo',
        ])->actingAs($user)->get(route('projects.index'));

        $response->assertOk();
        $response->assertSee('Demo mode enabled. Sensitive text is obfuscated.', false);
        $html = $response->getContent();
        $this->assertStringNotContainsString('PROJECT_INDEX_DEMO_TITLE_SECRET', $html);
        $this->assertStringNotContainsString('PROJECT_INDEX_DEMO_DESC_SECRET', $html);

        $response->assertSee('0 ideas', false);
        $response->assertSee('Updated', false);
        $response->assertSee(route('projects.show', $project), false);
        $response->assertDontSee('New project', false);

        $this->assertSame('PROJECT_INDEX_DEMO_TITLE_SECRET', $project->fresh()->title);
        $this->assertSame('PROJECT_INDEX_DEMO_DESC_SECRET', $project->fresh()->description);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        $normal = $this->actingAs($user)->get(route('projects.index'));
        $normal->assertSee('PROJECT_INDEX_DEMO_TITLE_SECRET', false);
        $normal->assertSee('PROJECT_INDEX_DEMO_DESC_SECRET', false);
        $normal->assertSee('New project', false);
    }
}
