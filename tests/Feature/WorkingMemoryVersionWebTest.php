<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingMemoryVersionWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_owner_can_see_global_version_history(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)->get(route('memory.versions'));

        $response->assertOk();
        $response->assertSee('Version history', false);
        $response->assertSee(route('memory.version.show', $version), false);
        $response->assertSee(route('memory.show'), false);
    }

    public function test_stranger_gets_403_on_project_version_history(): void
    {
        config(['features.working_memory_ui' => true]);
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $response = $this->actingAs($intruder)->get(route('projects.memory.versions', $project));

        $response->assertForbidden();
    }

    public function test_version_show_renders_external_fixture_section_heading(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => 'my-app',
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'summary_markdown' => "## Executive summary\n\n- Old thin summary.\n\n## Current Focus\n\n- Ship external snapshot.",
            'structured_sections_json' => [
                'Current Focus' => [[
                    'text' => 'Ship external snapshot.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [],
                ]],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('memory.version.show', $version));

        $response->assertOk();
        $response->assertSee('Historical snapshot', false);
        $response->assertSee('Current Focus', false);
        $response->assertSee('Ship external snapshot.', false);
        $response->assertDontSee('Executive summary', false);
    }
}
