<?php

namespace Tests\Feature;

use App\Jobs\BuildScopeDigestJob;
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

final class BuildScopeDigestJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_weekly_digest_compaction_for_active_scope(): void
    {
        // dispatchSync() pushes ShouldQueue jobs through the queue manager; Queue::fake()
        // would swallow that push, so the handler never runs.
        Queue::fakeExcept([BuildScopeDigestJob::class]);
        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => "## Latest Signals\n- Observability budget under review.",
            'structured_sections' => [
                'Latest Signals' => [['text' => 'Observability budget under review.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Active Priorities' => [],
                'Recent Changes' => [],
            ],
            'references' => [],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();

        Thought::factory()->count(4)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
            'created_at' => Carbon::parse('2026-05-04T10:00:00Z'),
        ]);

        BuildScopeDigestJob::dispatchSync($user->id, 'project', 'dezeen');

        $version = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:weekly-digest')
            ->first();

        $this->assertNotNull($version);
        $this->assertStringContainsString('Observability budget', (string) $version->summary_markdown);
        $this->assertSame('project', $version->workingMemory->scope_type);
        $this->assertSame('dezeen', $version->workingMemory->scope_key);
    }

    #[Test]
    public function it_skips_when_below_minimum_thoughts(): void
    {
        Queue::fakeExcept([BuildScopeDigestJob::class]);
        config(['working_memory.digest_min_thoughts' => 3]);
        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
            'created_at' => Carbon::parse('2026-05-04T10:00:00Z'),
        ]);

        BuildScopeDigestJob::dispatchSync($user->id, 'project', 'dezeen');

        $this->assertSame(0, WorkingMemoryVersion::query()->where('build_type', 'compaction:weekly-digest')->count());
    }

    #[Test]
    public function it_is_idempotent_when_a_recent_digest_already_exists(): void
    {
        Queue::fakeExcept([BuildScopeDigestJob::class]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();

        // Pre-existing digest one day before "now" — Eloquent timestamps honor Carbon::setTestNow().
        Carbon::setTestNow(Carbon::parse('2026-05-06T10:00:00Z'));
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'freshness_state' => 'stale',
        ]);
        $memory->versions()->create([
            'build_type' => 'compaction:weekly-digest',
            'summary_markdown' => 'existing',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);

        Thought::factory()->count(5)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));
        BuildScopeDigestJob::dispatchSync($user->id, 'project', 'dezeen');

        $this->assertSame(1, WorkingMemoryVersion::query()->where('build_type', 'compaction:weekly-digest')->count());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }
}
