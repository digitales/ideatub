<?php

namespace Tests\Unit\Services\Inbox\Generators;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\Inbox\Generators\StaleProjectMemoryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleProjectMemoryGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_project_memory_produces_inbox_payload(): void
    {
        config([
            'features.attention_pulse' => true,
            'pulse.memory_stale_days' => 14,
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['title' => 'Dezeen']);

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => $project->id,
            'freshness_state' => 'stale',
            'last_refreshed_at' => now()->subDays(20),
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'authoring_status' => 'validated',
            'build_type' => 'consolidated',
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $payloads = app(StaleProjectMemoryGenerator::class)->generate($user);

        $this->assertCount(1, $payloads);
        $this->assertSame('stale_project_memory', $payloads[0]['generator_type']);
        $this->assertSame('stale_project_memory:'.$memory->id, $payloads[0]['dedupe_key']);
    }
}
