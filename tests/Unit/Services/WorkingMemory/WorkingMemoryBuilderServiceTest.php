<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use InvalidArgumentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    public function it_requires_global_scope_to_use_global_scope_key(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('global');

        app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'project-1');
    }
}
