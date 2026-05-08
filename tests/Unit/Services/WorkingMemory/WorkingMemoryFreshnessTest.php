<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Services\WorkingMemory\MemoryInsightsService;
use App\Services\WorkingMemory\WorkingMemoryAiAuthorService;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use App\Services\WorkingMemory\WorkingMemoryConsolidationWindowResolver;
use App\Services\WorkingMemory\WorkingMemoryEvidencePackBuilder;
use App\Services\WorkingMemory\WorkingMemoryLegacyRowCitationResolver;
use App\Services\WorkingMemory\WorkingMemoryOutputValidator;
use App\Services\WorkingMemory\WorkingMemoryScopeNormalizer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class WorkingMemoryFreshnessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_last_known_good_version_when_latest_build_fails(): void
    {
        config([
            'features.working_memory_ai_authored' => false,
            'working_memory.authoring_enabled' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'UTC'));

        $user = User::factory()->create();

        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'content' => 'Working thread about rollout.',
            'metadata' => ['tags' => ['rollout']],
        ]);

        $builder = app(WorkingMemoryBuilderService::class);
        $firstVersion = $builder->buildConsolidated($user->id, 'global', 'global');

        /** @var WorkingMemoryAssembler&MockInterface $mockAssembler */
        $mockAssembler = Mockery::mock(WorkingMemoryAssembler::class, [
            app(WorkingMemoryScopeNormalizer::class),
            app(WorkingMemoryConsolidationWindowResolver::class),
        ])->makePartial();
        $mockAssembler->shouldReceive('assemblePayload')
            ->once()
            ->andThrow(new RuntimeException('synthesis failed'));

        $failingBuilder = new WorkingMemoryBuilderService(
            $mockAssembler,
            app(WorkingMemoryScopeNormalizer::class),
            app(WorkingMemoryConsolidationWindowResolver::class),
            app(MemoryInsightsService::class),
            app(WorkingMemoryEvidencePackBuilder::class),
            app(WorkingMemoryAiAuthorService::class),
            app(WorkingMemoryOutputValidator::class),
            app(WorkingMemoryLegacyRowCitationResolver::class),
        );

        $fallbackVersion = $failingBuilder->buildIncremental($user->id, 'global', 'global');

        $memory = WorkingMemory::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame($firstVersion->id, $memory->latest_version_id);
        $this->assertSame($firstVersion->id, $fallbackVersion->id);
        $this->assertSame('degraded', $memory->freshness_state);
    }

    #[Test]
    public function it_keeps_confidence_and_freshness_in_payload_after_fallback(): void
    {
        config([
            'features.working_memory_ai_authored' => false,
            'working_memory.authoring_enabled' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'UTC'));

        $user = User::factory()->create();

        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'content' => 'Working thread about rollout.',
            'metadata' => ['tags' => ['rollout']],
        ]);

        $builder = app(WorkingMemoryBuilderService::class);
        $firstVersion = $builder->buildConsolidated($user->id, 'global', 'global');
        $expectedSummary = $firstVersion->summary_markdown;
        $expectedConfidence = (float) $firstVersion->confidence_score;

        /** @var WorkingMemoryAssembler&MockInterface $mockAssembler */
        $mockAssembler = Mockery::mock(WorkingMemoryAssembler::class, [
            app(WorkingMemoryScopeNormalizer::class),
            app(WorkingMemoryConsolidationWindowResolver::class),
        ])->makePartial();
        $mockAssembler->shouldReceive('assemblePayload')
            ->once()
            ->andThrow(new RuntimeException('synthesis failed'));

        $failingBuilder = new WorkingMemoryBuilderService(
            $mockAssembler,
            app(WorkingMemoryScopeNormalizer::class),
            app(WorkingMemoryConsolidationWindowResolver::class),
            app(MemoryInsightsService::class),
            app(WorkingMemoryEvidencePackBuilder::class),
            app(WorkingMemoryAiAuthorService::class),
            app(WorkingMemoryOutputValidator::class),
            app(WorkingMemoryLegacyRowCitationResolver::class),
        );

        $failingBuilder->buildIncremental($user->id, 'global', 'global');

        $payload = app(WorkingMemoryAssembler::class)->forScope($user->id, 'global', 'global');

        $this->assertSame('degraded', $payload['freshness_state']);
        $this->assertSame($expectedSummary, $payload['summary_markdown']);
        $this->assertSame($expectedConfidence, $payload['confidence_score']);
    }

    #[Test]
    public function it_bubbles_non_runtime_failures_instead_of_falling_back(): void
    {
        config([
            'features.working_memory_ai_authored' => false,
            'working_memory.authoring_enabled' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'UTC'));

        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Thread content.',
            'metadata' => ['tags' => ['infra']],
        ]);

        app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');
        $memory = WorkingMemory::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('fresh', $memory->freshness_state);

        /** @var WorkingMemoryAssembler&MockInterface $mockAssembler */
        $mockAssembler = Mockery::mock(WorkingMemoryAssembler::class, [
            app(WorkingMemoryScopeNormalizer::class),
            app(WorkingMemoryConsolidationWindowResolver::class),
        ])->makePartial();
        $mockAssembler->shouldReceive('assemblePayload')
            ->once()
            ->andThrow(new \Error('db connection dropped'));

        $failingBuilder = new WorkingMemoryBuilderService(
            $mockAssembler,
            app(WorkingMemoryScopeNormalizer::class),
            app(WorkingMemoryConsolidationWindowResolver::class),
            app(MemoryInsightsService::class),
            app(WorkingMemoryEvidencePackBuilder::class),
            app(WorkingMemoryAiAuthorService::class),
            app(WorkingMemoryOutputValidator::class),
            app(WorkingMemoryLegacyRowCitationResolver::class),
        );

        try {
            $failingBuilder->buildIncremental($user->id, 'global', 'global');
            $this->fail('Expected Error');
        } catch (\Error $e) {
            $this->assertSame('db connection dropped', $e->getMessage());
        }

        $memory->refresh();
        $this->assertSame('fresh', $memory->freshness_state);
    }

    #[Test]
    public function it_does_not_mark_degraded_when_latest_version_pointer_is_missing(): void
    {
        config([
            'features.working_memory_ai_authored' => false,
            'working_memory.authoring_enabled' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'UTC'));

        $user = User::factory()->create();

        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'content' => 'Working thread about rollout.',
            'metadata' => ['tags' => ['rollout']],
        ]);

        app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');

        $memory = WorkingMemory::query()->where('user_id', $user->id)->firstOrFail();
        $memory->forceFill([
            'latest_version_id' => null,
            'freshness_state' => 'fresh',
        ])->save();

        /** @var WorkingMemoryAssembler&MockInterface $mockAssembler */
        $mockAssembler = Mockery::mock(WorkingMemoryAssembler::class, [
            app(WorkingMemoryScopeNormalizer::class),
            app(WorkingMemoryConsolidationWindowResolver::class),
        ])->makePartial();
        $mockAssembler->shouldReceive('assemblePayload')
            ->once()
            ->andThrow(new RuntimeException('synthesis failed'));

        $failingBuilder = new WorkingMemoryBuilderService(
            $mockAssembler,
            app(WorkingMemoryScopeNormalizer::class),
            app(WorkingMemoryConsolidationWindowResolver::class),
            app(MemoryInsightsService::class),
            app(WorkingMemoryEvidencePackBuilder::class),
            app(WorkingMemoryAiAuthorService::class),
            app(WorkingMemoryOutputValidator::class),
            app(WorkingMemoryLegacyRowCitationResolver::class),
        );

        try {
            $failingBuilder->buildIncremental($user->id, 'global', 'global');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('synthesis failed', $e->getMessage());
        }

        $memory->refresh();
        $this->assertSame('fresh', $memory->freshness_state);
    }

    #[Test]
    public function it_marks_memory_stale_when_no_refresh_within_24_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 10:00:00', 'UTC'));

        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Stale boundary content.',
            'metadata' => ['tags' => ['boundary']],
        ]);

        app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');

        Carbon::setTestNow(Carbon::parse('2026-05-06 11:00:01', 'UTC'));

        $payload = app(WorkingMemoryAssembler::class)->forScope($user->id, 'global', 'global');

        $this->assertSame('stale', $payload['freshness_state']);
    }

    #[Test]
    public function it_marks_memory_stale_at_exactly_24_hours_since_refresh(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 08:00:00', 'UTC'));

        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => '24 hour threshold content.',
            'metadata' => ['tags' => ['boundary']],
        ]);

        app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');

        Carbon::setTestNow(Carbon::parse('2026-05-06 08:00:00', 'UTC'));

        $payload = app(WorkingMemoryAssembler::class)->forScope($user->id, 'global', 'global');

        $this->assertSame('stale', $payload['freshness_state']);
    }

    #[Test]
    public function it_marks_memory_degraded_when_last_refresh_between_4_and_24_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 08:00:00', 'UTC'));

        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Degraded window content.',
            'metadata' => ['tags' => ['window']],
        ]);

        app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');

        Carbon::setTestNow(Carbon::parse('2026-05-05 14:00:00', 'UTC'));

        $payload = app(WorkingMemoryAssembler::class)->forScope($user->id, 'global', 'global');

        $this->assertSame('degraded', $payload['freshness_state']);
    }

    #[Test]
    public function it_marks_memory_degraded_at_exactly_4_hours_since_refresh(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 10:00:00', 'UTC'));

        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => '4 hour threshold content.',
            'metadata' => ['tags' => ['boundary']],
        ]);

        app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');

        Carbon::setTestNow(Carbon::parse('2026-05-05 14:00:00', 'UTC'));

        $payload = app(WorkingMemoryAssembler::class)->forScope($user->id, 'global', 'global');

        $this->assertSame('degraded', $payload['freshness_state']);
    }

    #[Test]
    public function it_reports_fresh_when_last_refresh_within_4_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'UTC'));

        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Fresh window content.',
            'metadata' => ['tags' => ['fresh']],
        ]);

        app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');

        Carbon::setTestNow(Carbon::parse('2026-05-05 14:30:00', 'UTC'));

        $payload = app(WorkingMemoryAssembler::class)->forScope($user->id, 'global', 'global');

        $this->assertSame('fresh', $payload['freshness_state']);
    }
}
