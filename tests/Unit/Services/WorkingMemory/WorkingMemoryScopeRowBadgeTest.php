<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryScopeRowBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingMemoryScopeRowBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_updating_when_build_started_at_is_set(): void
    {
        $memory = WorkingMemory::factory()->create([
            'build_started_at' => now(),
        ]);
        WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'authoring_status' => 'fallback',
        ]);
        $memory->update(['latest_version_id' => $memory->versions()->first()->id]);

        $this->assertSame('Updating', WorkingMemoryScopeRowBadge::label($memory->fresh(['latestVersion'])));
    }

    public function test_returns_fallback_when_not_building_and_latest_is_fallback(): void
    {
        $memory = WorkingMemory::factory()->create([
            'build_started_at' => null,
        ]);
        $version = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'authoring_status' => 'fallback',
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $this->assertSame('Fallback', WorkingMemoryScopeRowBadge::label($memory->fresh(['latestVersion'])));
    }

    public function test_returns_null_when_validated(): void
    {
        $memory = WorkingMemory::factory()->create(['build_started_at' => null]);
        $version = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'authoring_status' => 'validated',
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $this->assertNull(WorkingMemoryScopeRowBadge::label($memory->fresh(['latestVersion'])));
    }
}
