<?php

namespace Tests\Unit\Services\Attention;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\Attention\MemoryHealthAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryHealthAttentionQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_fallback_scope_returns_high_severity_row_with_memory_href(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
            'last_refreshed_at' => now(),
            'freshness_state' => 'fresh',
        ]);

        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'authoring_status' => 'fallback',
            'build_type' => 'consolidated',
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $items = app(MemoryHealthAttentionQuery::class)->forUser($user->id);

        $this->assertCount(1, $items);
        $this->assertSame('high', $items[0]->severity);
        $this->assertSame('Global', $items[0]->title);
        $this->assertSame(route('memory.show'), $items[0]->href);
    }

    public function test_stale_project_memory_returns_medium_or_low_signal(): void
    {
        config([
            'features.working_memory_ui' => true,
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

        $items = app(MemoryHealthAttentionQuery::class)->forUser($user->id);

        $this->assertNotEmpty($items);
        $this->assertSame(route('projects.memory.show', $project), $items[0]->href);
    }

    public function test_ephemeral_tag_fallbacks_are_grouped_into_summary_row(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();

        foreach (['workshop', 'developer-event', 'ai-edge-programme'] as $tagKey) {
            $memory = WorkingMemory::factory()->for($user)->create([
                'scope_type' => 'tag',
                'scope_key' => $tagKey,
                'last_refreshed_at' => now(),
                'freshness_state' => 'fresh',
            ]);
            $version = WorkingMemoryVersion::factory()->for($memory)->create([
                'authoring_status' => 'fallback',
                'build_type' => 'consolidated',
            ]);
            $memory->update(['latest_version_id' => $version->id]);
        }

        $grouped = app(MemoryHealthAttentionQuery::class)->groupedForUser($user->id);

        $this->assertSame([], $grouped['operational']);
        $this->assertCount(1, $grouped['tag']);
        $this->assertSame('tag_memory_summary', $grouped['tag'][0]->kind);
        $this->assertSame('Ephemeral tag memory', $grouped['tag'][0]->title);
        $this->assertSame(3, $grouped['tag'][0]->meta['tag_fallback_count'] ?? null);
    }

    public function test_forced_tag_fallback_shows_individual_row_in_tag_section(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();
        \App\Models\UserPreference::set($user, \App\Models\UserPreference::KEY_WORKING_MEMORY_FORCED_TAGS, ['workshop']);

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'tag',
            'scope_key' => 'workshop',
            'last_refreshed_at' => now(),
            'freshness_state' => 'fresh',
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'authoring_status' => 'fallback',
            'build_type' => 'consolidated',
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $grouped = app(MemoryHealthAttentionQuery::class)->groupedForUser($user->id);

        $this->assertCount(1, $grouped['tag']);
        $this->assertSame('memory_health', $grouped['tag'][0]->kind);
        $this->assertSame('Workshop', $grouped['tag'][0]->title);
    }
}
