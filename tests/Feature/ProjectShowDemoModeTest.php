<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectShowDemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_demo_mode_obfuscates_project_show_without_mutating_records(): void
    {
        config([
            'services.demo_mode.enabled' => true,
            'features.working_memory_ui' => true,
            'features.file_upload' => true,
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'title' => 'PROJECT_SHOW_DEMO_TITLE_SECRET',
            'description' => 'PROJECT_SHOW_DEMO_DESC_SECRET',
        ]);
        $context = Thought::factory()->for($user)->create([
            'content' => 'PROJECT_SHOW_CONTEXT_SECRET',
        ]);
        $member = Thought::factory()->for($user)->create([
            'content' => "# MEMBER_ROW_TITLE_SECRET\n\nMEMBER_ROW_EXCERPT_SECRET",
        ]);
        $project->update(['context_thought_id' => $context->id]);
        $project->thoughts()->attach($member->id, ['sort_order' => 0]);

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => strtolower((string) $project->id),
            'last_refreshed_at' => now()->subHour(),
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'structured_sections_json' => [
                'Current Focus' => [[
                    'text' => 'WM_INLINE_SECRET_FOCUS',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [],
                ]],
            ],
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'feat-seed-project-show-demo',
        ])->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('Demo mode enabled. Sensitive text is obfuscated.', false);
        $html = $response->getContent();
        $this->assertStringNotContainsString('PROJECT_SHOW_DEMO_TITLE_SECRET', $html);
        $this->assertStringNotContainsString('PROJECT_SHOW_DEMO_DESC_SECRET', $html);
        $this->assertStringNotContainsString('PROJECT_SHOW_CONTEXT_SECRET', $html);
        $this->assertStringNotContainsString('MEMBER_ROW_TITLE_SECRET', $html);
        $this->assertStringNotContainsString('MEMBER_ROW_EXCERPT_SECRET', $html);
        $this->assertStringNotContainsString('WM_INLINE_SECRET_FOCUS', $html);

        $response->assertSee('Contents', false);
        $response->assertSee('1 idea', false);
        $response->assertDontSee('Add thought', false);
        $response->assertDontSee('Import markdown', false);
        $response->assertDontSee('Pin as context', false);
        $response->assertDontSee('>Remove<', false);
        $response->assertDontSee('Unpin', false);
        $response->assertDontSee('Working memory', false);

        $this->assertSame('PROJECT_SHOW_DEMO_TITLE_SECRET', $project->fresh()->title);
        $this->assertSame('PROJECT_SHOW_CONTEXT_SECRET', $context->fresh()->content);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        $normal = $this->actingAs($user)->get(route('projects.show', $project));
        $normal->assertSee('PROJECT_SHOW_DEMO_TITLE_SECRET', false);
        $normal->assertSee('PROJECT_SHOW_CONTEXT_SECRET', false);
    }
}
