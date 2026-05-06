<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_consolidated_version_with_required_sections(): void
    {
        $user = User::factory()->create();

        Thought::factory()->count(3)->create([
            'user_id' => $user->id,
            'content' => 'Plan API cleanup and finalize deployment checklist.',
            'metadata' => ['tags' => ['api', 'cleanup']],
        ]);

        $version = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'global');

        $this->assertSame('consolidated', $version->build_type);
        $this->assertStringContainsString('## Executive summary', $version->summary_markdown);
        $this->assertStringContainsString('## Key concepts', $version->summary_markdown);
        $this->assertStringContainsString('## Active threads', $version->summary_markdown);
        $this->assertStringContainsString('## Open questions', $version->summary_markdown);
        $this->assertStringContainsString('## Next actions', $version->summary_markdown);
        $this->assertSame($version->id, $version->workingMemory->latest_version_id);
    }

    #[Test]
    public function it_persists_source_thought_links_for_traceability(): void
    {
        $user = User::factory()->create();

        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'source_metadata' => ['project' => 'my-app'],
            'content' => 'Investigate queue worker restart issue for my-app.',
            'metadata' => ['tags' => ['ops', 'queue']],
        ]);

        $version = app(WorkingMemoryBuilderService::class)
            ->buildIncremental($user->id, 'project', 'my-app');

        $this->assertGreaterThan(0, $version->inputs()->count());
    }

    #[Test]
    public function it_bounds_confidence_score_between_zero_and_one_hundred(): void
    {
        $user = User::factory()->create();

        Thought::factory()->count(30)->create([
            'user_id' => $user->id,
            'content' => 'Follow up on integration test failures and update status.',
            'metadata' => ['tags' => ['testing', 'integration']],
        ]);

        $version = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'global');

        $score = (float) $version->confidence_score;

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }

    #[Test]
    public function it_rejects_unknown_scope_type(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid scope_type');

        app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'team', 'my-app');
    }

    #[Test]
    public function it_rejects_empty_scope_key(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scope_key');

        app(WorkingMemoryBuilderService::class)
            ->buildIncremental($user->id, 'project', '   ');
    }

    #[Test]
    public function it_rejects_empty_tag_scope_key(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scope_key');

        app(WorkingMemoryBuilderService::class)
            ->buildIncremental($user->id, 'tag', '   ');
    }

    #[Test]
    public function it_requires_global_scope_to_use_global_scope_key(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('global');

        app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'project-1');
    }

    #[Test]
    public function it_requires_insights_scope_to_use_global_scope_key(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('global');

        app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'insights', 'other');
    }

    #[Test]
    public function it_persists_insights_version_with_research_thoughts(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => "Line one\n\nMore body.",
            'metadata' => ['type' => 'research', 'tags' => ['alpha', 'beta']],
        ]);

        $version = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'insights', 'global');

        $this->assertSame('consolidated', $version->build_type);
        $this->assertStringContainsString('# Memory insights', $version->summary_markdown);
        $this->assertStringContainsString('## Themes', $version->summary_markdown);
        $this->assertSame(1, $version->inputs()->count());
    }

    #[Test]
    public function consolidated_build_excludes_thoughts_older_than_consolidation_window(): void
    {
        try {
            Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'UTC'));

            $user = User::factory()->create();

            $recent = Thought::factory()->create([
                'user_id' => $user->id,
                'content' => 'Recent idea within the consolidation window.',
                'created_at' => Carbon::parse('2026-05-01 10:00:00', 'UTC'),
            ]);

            Thought::factory()->create([
                'user_id' => $user->id,
                'content' => 'Stale idea outside the default 180-day window.',
                'created_at' => Carbon::parse('2025-06-01 10:00:00', 'UTC'),
            ]);

            $version = app(WorkingMemoryBuilderService::class)
                ->buildConsolidated($user->id, 'global', 'global');

            $this->assertSame(1, $version->inputs()->count());
            $this->assertTrue($version->inputs()->pluck('thought_id')->containsStrict($recent->id));
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function consolidated_build_respects_user_consolidation_window_preference(): void
    {
        try {
            Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'UTC'));

            config(['working_memory.consolidation_window_days' => 180]);

            $user = User::factory()->create();
            UserPreference::set($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS, 30);

            $recent = Thought::factory()->create([
                'user_id' => $user->id,
                'content' => 'Recent thought within the 30-day user window.',
                'created_at' => Carbon::parse('2026-04-30 10:00:00', 'UTC'),
            ]);

            Thought::factory()->create([
                'user_id' => $user->id,
                'content' => 'Older thought outside the 30-day user window.',
                'created_at' => Carbon::parse('2026-03-27 10:00:00', 'UTC'),
            ]);

            $version = app(WorkingMemoryBuilderService::class)
                ->buildConsolidated($user->id, 'global', 'global');

            $this->assertSame(1, $version->inputs()->count());
            $this->assertTrue($version->inputs()->pluck('thought_id')->containsStrict($recent->id));
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function consolidated_tag_scope_selects_only_matching_tagged_thoughts(): void
    {
        try {
            Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'UTC'));

            $user = User::factory()->create();

            $matching = Thought::factory()->create([
                'user_id' => $user->id,
                'content' => 'Ship AI summary improvements in working memory.',
                'metadata' => ['tags' => [' AI ', 'memory']],
                'created_at' => Carbon::parse('2026-05-03 10:00:00', 'UTC'),
            ]);

            Thought::factory()->create([
                'user_id' => $user->id,
                'content' => 'Project roadmap update for dashboards.',
                'metadata' => ['tags' => ['roadmap', 'dashboard']],
                'created_at' => Carbon::parse('2026-05-02 10:00:00', 'UTC'),
            ]);

            Thought::factory()->create([
                'user_id' => $user->id,
                'content' => 'No tags here.',
                'metadata' => [],
                'created_at' => Carbon::parse('2026-05-01 10:00:00', 'UTC'),
            ]);

            $version = app(WorkingMemoryBuilderService::class)
                ->buildConsolidated($user->id, 'tag', '  AI  ');

            $this->assertSame('tag', $version->workingMemory->scope_type);
            $this->assertSame('ai', $version->workingMemory->scope_key);
            $this->assertSame(1, $version->inputs()->count());
            $this->assertTrue($version->inputs()->pluck('thought_id')->containsStrict($matching->id));
        } finally {
            Carbon::setTestNow();
        }
    }
}
