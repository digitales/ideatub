<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryExternalGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingMemoryExternalGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_consolidation_when_fresh_external_exists(): void
    {
        config(['working_memory.external_protect_days' => 14]);
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subDay(),
        ]);

        $guard = app(WorkingMemoryExternalGuard::class);

        $this->assertTrue($guard->shouldSkipConsolidatedBuild(
            $user->id,
            'project',
            '019e0705-5591-73e9-be2e-0fb9c86b269a',
            force: false,
        ));
    }

    public function test_force_bypasses_guard(): void
    {
        config(['working_memory.external_protect_days' => 14]);
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subDay(),
        ]);

        $guard = app(WorkingMemoryExternalGuard::class);

        $this->assertFalse($guard->shouldSkipConsolidatedBuild(
            $user->id,
            'project',
            '019e0705-5591-73e9-be2e-0fb9c86b269a',
            force: true,
        ));
    }

    public function test_does_not_skip_when_external_is_stale(): void
    {
        config(['working_memory.external_protect_days' => 14]);
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subDays(30),
        ]);

        $guard = app(WorkingMemoryExternalGuard::class);

        $this->assertFalse($guard->shouldSkipConsolidatedBuild(
            $user->id,
            'project',
            '019e0705-5591-73e9-be2e-0fb9c86b269a',
            force: false,
        ));
    }
}
