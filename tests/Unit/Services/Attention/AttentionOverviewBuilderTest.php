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

    public function test_build_splits_tag_memory_into_separate_section(): void
    {
        config([
            'features.working_memory_ui' => true,
            'features.attention_pulse' => true,
        ]);

        $user = User::factory()->create();

        $global = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
            'last_refreshed_at' => now(),
        ]);
        $globalVersion = WorkingMemoryVersion::factory()->for($global)->create([
            'authoring_status' => 'fallback',
            'build_type' => 'consolidated',
        ]);
        $global->update(['latest_version_id' => $globalVersion->id]);

        $tag = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'tag',
            'scope_key' => 'workshop',
            'last_refreshed_at' => now(),
        ]);
        $tagVersion = WorkingMemoryVersion::factory()->for($tag)->create([
            'authoring_status' => 'fallback',
            'build_type' => 'consolidated',
        ]);
        $tag->update(['latest_version_id' => $tagVersion->id]);

        $overview = app(AttentionOverviewBuilder::class)->build($user->id);

        $this->assertSame(['memory_health', 'tag_memory_health'], array_map(fn ($section) => $section->key, $overview->sections));
    }
}
