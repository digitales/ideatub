<?php

namespace Tests\Unit\Models;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryInput;
use App\Models\WorkingMemoryVersion;
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
}
