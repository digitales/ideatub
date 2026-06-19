<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\UncompactedThoughtResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UncompactedThoughtResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_detects_uncompacted_thoughts_after_latest_compaction(): void
    {
        config(['working_memory.compaction_primary' => true]);

        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->create([
            'user_id' => $user->id,
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);

        $digested = Thought::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::parse('2026-05-05 10:00:00', 'UTC'),
        ]);

        WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:weekly-digest',
            'created_at' => Carbon::parse('2026-05-06 10:00:00', 'UTC'),
        ]);

        $fresh = Thought::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::parse('2026-05-07 10:00:00', 'UTC'),
        ]);

        $resolver = app(UncompactedThoughtResolver::class);

        $uncompacted = $resolver->uncompactedThoughts($user->id, 'global', 'global', 'incremental');
        $this->assertCount(1, $uncompacted);
        $this->assertSame((string) $fresh->id, (string) $uncompacted->first()->id);
        $this->assertNotContains((string) $digested->id, $uncompacted->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    #[Test]
    public function it_skips_incremental_build_when_no_delta_exists(): void
    {
        config(['working_memory.compaction_primary' => true]);

        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->create([
            'user_id' => $user->id,
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::parse('2026-05-05 10:00:00', 'UTC'),
        ]);

        $compactionAt = Carbon::parse('2026-05-06 10:00:00', 'UTC');
        WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:weekly-digest',
            'created_at' => $compactionAt,
        ]);

        WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'incremental',
            'created_at' => Carbon::parse('2026-05-06 11:00:00', 'UTC'),
        ]);

        $resolver = app(UncompactedThoughtResolver::class);

        $this->assertFalse($resolver->shouldRunIncrementalBuild($user->id, 'global', 'global'));
    }

    #[Test]
    public function it_runs_incremental_build_when_a_new_compaction_arrives_after_last_incremental(): void
    {
        config(['working_memory.compaction_primary' => true]);

        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->create([
            'user_id' => $user->id,
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);

        WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'incremental',
            'created_at' => Carbon::parse('2026-05-06 09:00:00', 'UTC'),
        ]);

        WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:meeting',
            'created_at' => Carbon::parse('2026-05-06 10:00:00', 'UTC'),
        ]);

        $resolver = app(UncompactedThoughtResolver::class);

        $this->assertTrue($resolver->shouldRunIncrementalBuild($user->id, 'global', 'global'));
    }
}
