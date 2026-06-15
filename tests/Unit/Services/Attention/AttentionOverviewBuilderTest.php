<?php

namespace Tests\Unit\Services\Attention;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\Attention\AttentionOverviewBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttentionOverviewBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_returns_ordered_sections_and_omits_empty_ones(): void
    {
        config([
            'features.working_memory_ui' => true,
            'features.attention_pulse' => true,
        ]);

        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'authoring_status' => 'fallback',
            'build_type' => 'consolidated',
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $overview = app(AttentionOverviewBuilder::class)->build($user->id);

        $this->assertGreaterThan(0, $overview->totalCount());
        $this->assertSame(['memory_health'], array_map(fn ($section) => $section->key, $overview->sections));
    }
}
