<?php

namespace Tests\Feature;

use App\Jobs\BuildTopicDigestJob;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BuildTopicDigestJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_topic_digest_compaction_when_topic_thoughts_exist(): void
    {
        Queue::fakeExcept([BuildTopicDigestJob::class]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => "## Active Priorities\n- Pricing rollback is in flight.",
            'structured_sections' => [
                'Active Priorities' => [['text' => 'Pricing rollback is in flight.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Open Questions' => [],
                'Latest Signals' => [],
            ],
            'references' => [],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(3)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen', 'pricing']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);
        // Untagged thought — must be ignored by topic filter.
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        BuildTopicDigestJob::dispatchSync($user->id, 'project', 'dezeen', 'pricing');

        $version = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:topic-digest')
            ->first();

        $this->assertNotNull($version);
        $this->assertStringContainsString('Pricing rollback', (string) $version->summary_markdown);
        $this->assertSame('project', $version->workingMemory->scope_type);
        $this->assertSame('dezeen', $version->workingMemory->scope_key);
    }

    #[Test]
    public function it_skips_when_no_thoughts_carry_the_topic_tag(): void
    {
        Queue::fakeExcept([BuildTopicDigestJob::class]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        BuildTopicDigestJob::dispatchSync($user->id, 'project', 'dezeen', 'pricing');

        $this->assertSame(0, WorkingMemoryVersion::query()->where('build_type', 'compaction:topic-digest')->count());
    }

    #[Test]
    public function it_links_only_topic_tagged_thoughts_as_inputs(): void
    {
        Queue::fakeExcept([BuildTopicDigestJob::class]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => "## Active Priorities\n- Pricing rollback in flight.",
            'structured_sections' => [
                'Active Priorities' => [['text' => 'Pricing rollback in flight.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Open Questions' => [],
                'Latest Signals' => [],
            ],
            'references' => [],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        $tagged = Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen', 'pricing']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);
        $untagged = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        BuildTopicDigestJob::dispatchSync($user->id, 'project', 'dezeen', 'pricing');

        $version = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:topic-digest')
            ->firstOrFail();

        $linkedThoughtIds = \App\Models\WorkingMemoryInput::query()
            ->where('working_memory_version_id', $version->id)
            ->pluck('thought_id')
            ->map(fn ($id) => (string) $id)
            ->sort()
            ->values()
            ->all();

        $expected = $tagged->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $this->assertSame($expected, $linkedThoughtIds);
        $this->assertNotContains((string) $untagged->id, $linkedThoughtIds);
    }

    #[Test]
    public function it_works_when_scope_key_equals_topic_under_tag_scope(): void
    {
        Queue::fakeExcept([BuildTopicDigestJob::class]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => "## Active Priorities\n- Pricing focus.",
            'structured_sections' => [
                'Active Priorities' => [['text' => 'Pricing focus.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Open Questions' => [],
                'Latest Signals' => [],
            ],
            'references' => [],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['pricing']],
        ]);

        BuildTopicDigestJob::dispatchSync($user->id, 'tag', 'pricing', 'pricing');

        $version = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:topic-digest')
            ->first();

        $this->assertNotNull($version);
        $this->assertSame('tag', $version->workingMemory->scope_type);
        $this->assertSame('pricing', $version->workingMemory->scope_key);
    }

    #[Test]
    public function it_handles_uuid_project_scope_keys_via_source_metadata_match(): void
    {
        Queue::fakeExcept([BuildTopicDigestJob::class]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => "## Active Priorities\n- UUID project work.",
            'structured_sections' => [
                'Active Priorities' => [['text' => 'UUID project work.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Open Questions' => [],
                'Latest Signals' => [],
            ],
            'references' => [],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        $projectUuid = '11111111-1111-4111-8111-111111111111';
        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['pricing']],
            'source_metadata' => ['project' => $projectUuid],
        ]);

        BuildTopicDigestJob::dispatchSync($user->id, 'project', $projectUuid, 'pricing');

        $version = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:topic-digest')
            ->first();

        $this->assertNotNull($version);
        $this->assertSame($projectUuid, $version->workingMemory->scope_key);
    }

    #[Test]
    public function it_logs_a_warning_and_skips_persistence_when_model_returns_non_json(): void
    {
        Queue::fakeExcept([BuildTopicDigestJob::class]);
        \Illuminate\Support\Facades\Log::spy();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn('this is not json at all');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen', 'pricing']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        BuildTopicDigestJob::dispatchSync($user->id, 'project', 'dezeen', 'pricing');

        $this->assertSame(0, WorkingMemoryVersion::query()->where('build_type', 'compaction:topic-digest')->count());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'BuildTopicDigestJob'))
            ->atLeast()->once();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
