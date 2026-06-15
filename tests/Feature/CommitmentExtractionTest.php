<?php

namespace Tests\Feature;

use App\Models\CommitmentItem;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\Commitments\CommitmentExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommitmentExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_compaction_extractor_creates_commitment_items(): void
    {
        config(['features.attention_pulse' => true]);
        $user = User::factory()->create();

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);

        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'compaction:meeting',
            'structured_sections_json' => [
                'Action Items' => ['Send recap email'],
            ],
        ]);

        $created = app(CommitmentExtractor::class)->fromMeetingCompaction($version->load('workingMemory'));

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('commitment_items', [
            'user_id' => $user->id,
            'type' => 'meeting_action',
            'status' => 'open',
            'title' => 'Send recap email',
        ]);
    }

    public function test_working_memory_version_extractor_creates_next_actions(): void
    {
        config(['features.attention_pulse' => true]);
        $user = User::factory()->create();

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);

        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'consolidated',
            'authoring_status' => 'validated',
            'structured_sections_json' => [
                'Next Actions' => ['Run consolidate'],
            ],
        ]);

        $created = app(CommitmentExtractor::class)->fromWorkingMemoryVersion($version->load('workingMemory'));

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('commitment_items', [
            'user_id' => $user->id,
            'type' => 'wm_next_action',
            'status' => 'open',
        ]);
    }
}
