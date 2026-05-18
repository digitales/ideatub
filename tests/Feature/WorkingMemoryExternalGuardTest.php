<?php

namespace Tests\Feature;

use App\Jobs\ConsolidateWorkingMemory;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingMemoryExternalGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_consolidate_job_skips_build_when_fresh_external_exists(): void
    {
        config(['working_memory.external_protect_days' => 14]);
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => (string) $project->getKey(),
        ]);
        $external = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subHour(),
        ]);
        $memory->update(['latest_version_id' => $external->id]);
        $countBefore = $memory->versions()->count();

        $job = new ConsolidateWorkingMemory($user->id, 'project', $memory->scope_key);
        $job->handle(app(WorkingMemoryBuilderService::class));

        $this->assertSame($countBefore, $memory->fresh()->versions()->count());
    }

    public function test_consolidate_job_runs_when_force_true(): void
    {
        config(['working_memory.external_protect_days' => 14]);
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => (string) $project->getKey(),
        ]);
        $external = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subHour(),
        ]);
        $memory->update(['latest_version_id' => $external->id]);

        $this->mock(WorkingMemoryBuilderService::class, function ($mock) use ($user, $memory): void {
            $mock->shouldReceive('buildConsolidated')
                ->once()
                ->with($user->id, 'project', $memory->scope_key)
                ->andReturn(WorkingMemoryVersion::factory()->for($memory)->make());
        });

        $job = new ConsolidateWorkingMemory($user->id, 'project', $memory->scope_key, force: true);
        $job->handle(app(WorkingMemoryBuilderService::class));
    }
}
