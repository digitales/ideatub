<?php

namespace Tests\Unit\Models;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryInput;
use App\Models\WorkingMemoryVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryInputTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_link_to_a_compaction_version_instead_of_a_thought(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'freshness_state' => 'stale',
        ]);
        $compaction = WorkingMemoryVersion::create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:meeting',
            'summary_markdown' => 'compaction',
        ]);
        $canonical = WorkingMemoryVersion::create([
            'working_memory_id' => $memory->id,
            'build_type' => 'consolidated',
            'summary_markdown' => 'canonical',
        ]);

        $input = WorkingMemoryInput::create([
            'working_memory_version_id' => $canonical->id,
            'thought_id' => null,
            'source_version_id' => $compaction->id,
            'contribution_type' => 'compaction',
            'weight' => 1.0,
        ]);

        $this->assertNull($input->thought_id);
        $this->assertSame($compaction->id, $input->source_version_id);
        $this->assertSame($compaction->id, $input->sourceVersion->id);
    }

    #[Test]
    public function thought_inputs_still_work_unchanged(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'wm-input-thought-test',
            'freshness_state' => 'stale',
        ]);
        $version = WorkingMemoryVersion::create([
            'working_memory_id' => $memory->id,
            'build_type' => 'incremental',
            'summary_markdown' => 'v',
        ]);

        $input = WorkingMemoryInput::create([
            'working_memory_version_id' => $version->id,
            'thought_id' => $thought->id,
            'source_version_id' => null,
            'contribution_type' => 'primary',
            'weight' => 1.0,
        ]);

        $this->assertSame($thought->id, $input->thought_id);
        $this->assertNull($input->source_version_id);
    }

    #[Test]
    public function it_rejects_an_input_with_both_thought_id_and_source_version_id(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'wm-input-both-test',
            'freshness_state' => 'stale',
        ]);
        $version = WorkingMemoryVersion::create([
            'working_memory_id' => $memory->id,
            'build_type' => 'consolidated',
            'summary_markdown' => 'v',
        ]);
        $other = WorkingMemoryVersion::create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:meeting',
            'summary_markdown' => 'c',
        ]);

        $this->expectException(QueryException::class);

        WorkingMemoryInput::create([
            'working_memory_version_id' => $version->id,
            'thought_id' => $thought->id,
            'source_version_id' => $other->id,
            'contribution_type' => 'primary',
            'weight' => 1.0,
        ]);
    }

    #[Test]
    public function it_rejects_an_input_with_neither_thought_id_nor_source_version_id(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'wm-input-neither-test',
            'freshness_state' => 'stale',
        ]);
        $version = WorkingMemoryVersion::create([
            'working_memory_id' => $memory->id,
            'build_type' => 'consolidated',
            'summary_markdown' => 'v',
        ]);

        $this->expectException(QueryException::class);

        WorkingMemoryInput::create([
            'working_memory_version_id' => $version->id,
            'thought_id' => null,
            'source_version_id' => null,
            'contribution_type' => 'primary',
            'weight' => 1.0,
        ]);
    }

    #[Test]
    public function it_rejects_duplicate_compaction_inputs_for_the_same_version(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'wm-input-source-unique-test',
            'freshness_state' => 'stale',
        ]);
        $compaction = WorkingMemoryVersion::create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:meeting',
            'summary_markdown' => 'c',
        ]);
        $canonical = WorkingMemoryVersion::create([
            'working_memory_id' => $memory->id,
            'build_type' => 'consolidated',
            'summary_markdown' => 'v',
        ]);

        WorkingMemoryInput::create([
            'working_memory_version_id' => $canonical->id,
            'thought_id' => null,
            'source_version_id' => $compaction->id,
            'contribution_type' => 'compaction',
            'weight' => 1.0,
        ]);

        $this->expectException(QueryException::class);

        WorkingMemoryInput::create([
            'working_memory_version_id' => $canonical->id,
            'thought_id' => null,
            'source_version_id' => $compaction->id,
            'contribution_type' => 'compaction',
            'weight' => 0.5,
        ]);
    }
}
