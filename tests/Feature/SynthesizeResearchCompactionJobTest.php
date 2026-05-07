<?php

namespace Tests\Feature;

use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SynthesizeResearchCompactionJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_research_compaction_when_threshold_met(): void
    {
        // ShouldQueue + dispatchSync routes through the queue manager — fake everything except the job under test.
        Queue::fakeExcept([SynthesizeResearchCompactionJob::class]);
        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));
        config(['working_memory.research_synth_min_thoughts' => 3]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => "## Open Questions\n- Does workload X exhibit MVCC bloat?",
            'structured_sections' => [
                'Open Questions' => [['text' => 'Does workload X exhibit MVCC bloat?', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Risks / Blockers' => [],
                'Latest Signals' => [],
                'Source Notes' => [],
            ],
            'references' => [],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(3)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => ['research', 'project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
            'created_at' => Carbon::parse('2026-05-05T10:00:00Z'),
        ]);

        SynthesizeResearchCompactionJob::dispatchSync($user->id, 'project', 'dezeen');

        $version = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:research-synth')
            ->first();

        $this->assertNotNull($version);
        $this->assertStringContainsString('MVCC bloat', (string) $version->summary_markdown);
    }

    #[Test]
    public function it_skips_below_threshold(): void
    {
        Queue::fakeExcept([SynthesizeResearchCompactionJob::class]);
        config(['working_memory.research_synth_min_thoughts' => 5]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => ['research', 'project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        SynthesizeResearchCompactionJob::dispatchSync($user->id, 'project', 'dezeen');

        $this->assertSame(0, WorkingMemoryVersion::query()->where('build_type', 'compaction:research-synth')->count());
    }

    #[Test]
    public function it_skips_when_recent_research_compaction_exists(): void
    {
        Queue::fakeExcept([SynthesizeResearchCompactionJob::class]);
        config([
            'working_memory.research_synth_min_thoughts' => 1,
            'working_memory.research_synth_freshness_hours' => 168,
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();

        // Pre-existing compaction at "yesterday" so it's inside the 168h freshness window.
        Carbon::setTestNow(Carbon::parse('2026-05-06T10:00:00Z'));
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'freshness_state' => 'stale',
        ]);
        $memory->versions()->create([
            'build_type' => 'compaction:research-synth',
            'summary_markdown' => 'existing',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);

        Thought::factory()->count(5)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => ['research', 'project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));
        SynthesizeResearchCompactionJob::dispatchSync($user->id, 'project', 'dezeen');

        $this->assertSame(1, WorkingMemoryVersion::query()->where('build_type', 'compaction:research-synth')->count());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }
}
