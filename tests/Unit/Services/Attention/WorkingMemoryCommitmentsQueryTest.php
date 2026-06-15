<?php

namespace Tests\Unit\Services\Attention;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\Attention\WorkingMemoryCommitmentsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingMemoryCommitmentsQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_validated_project_memory_returns_next_actions(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['title' => 'IdeaTub']);

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => $project->id,
        ]);

        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'authoring_status' => 'validated',
            'build_type' => 'consolidated',
            'structured_sections_json' => [
                'Next Actions' => ['Ship pulse dashboard'],
                'Open Questions' => ['When to consolidate global?'],
            ],
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $items = app(WorkingMemoryCommitmentsQuery::class)->forUser($user->id);

        $this->assertCount(2, $items);
        $this->assertSame('wm_next_action', $items[0]->kind);
        $this->assertStringContainsString('Ship pulse dashboard', $items[0]->title);
        $this->assertSame(route('projects.memory.show', $project), $items[0]->href);
    }
}
