<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\Compactions\CompactionRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompactionRetentionServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_trims_compactions_per_subtype_to_configured_cap(): void
    {
        config(['working_memory.compaction_retention.meeting' => 3]);

        $user = User::factory()->create();
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'freshness_state' => 'stale',
        ]);

        // Five meeting compactions; sequence with Carbon::setTestNow because created_at is not in $fillable.
        for ($i = 0; $i < 5; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-05-01T00:00:00Z')->addHours($i));
            $memory->versions()->create([
                'build_type' => 'compaction:meeting',
                'summary_markdown' => "v{$i}",
                'structured_sections_json' => [],
                'references_json' => [],
                'authoring_status' => 'validated',
                'confidence_score' => 0,
            ]);
        }
        Carbon::setTestNow();

        $service = new CompactionRetentionService;
        $service->trim($memory, 'compaction:meeting');

        $surviving = WorkingMemoryVersion::query()
            ->where('working_memory_id', $memory->id)
            ->where('build_type', 'compaction:meeting')
            ->orderBy('created_at')
            ->get();

        $this->assertCount(3, $surviving);
        $this->assertSame(['v2', 'v3', 'v4'], $surviving->pluck('summary_markdown')->all());
    }

    #[Test]
    public function it_does_not_touch_other_subtypes_or_canonical_versions(): void
    {
        config(['working_memory.compaction_retention.meeting' => 1]);

        $user = User::factory()->create();
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'freshness_state' => 'stale',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-01T00:00:00Z'));
        $memory->versions()->create([
            'build_type' => 'compaction:meeting',
            'summary_markdown' => 'old',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-02T00:00:00Z'));
        $memory->versions()->create([
            'build_type' => 'compaction:meeting',
            'summary_markdown' => 'new',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);
        $memory->versions()->create([
            'build_type' => 'compaction:weekly-digest',
            'summary_markdown' => 'digest',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);
        $memory->versions()->create([
            'build_type' => 'consolidated',
            'summary_markdown' => 'canonical',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);
        Carbon::setTestNow();

        $service = new CompactionRetentionService;
        $service->trim($memory, 'compaction:meeting');

        $this->assertSame(1, WorkingMemoryVersion::query()->where('build_type', 'compaction:meeting')->count());
        $this->assertSame(1, WorkingMemoryVersion::query()->where('build_type', 'compaction:weekly-digest')->count());
        $this->assertSame(1, WorkingMemoryVersion::query()->where('build_type', 'consolidated')->count());
    }
}
