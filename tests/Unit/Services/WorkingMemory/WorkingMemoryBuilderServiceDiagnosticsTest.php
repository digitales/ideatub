<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryBuilderServiceDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function build_diagnostics_includes_compaction_coverage_fields(): void
    {
        Queue::fake();
        config([
            'working_memory.authoring_enabled' => true,
            'features.working_memory_ai_authored' => true,
        ]);

        $user = User::factory()->create();

        // Pre-existing meeting compaction in the scope.
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'freshness_state' => 'stale',
        ]);
        $compaction = $memory->versions()->create([
            'build_type' => 'compaction:meeting',
            'summary_markdown' => "## Summary\nWeekly check-in.",
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Need to ship DEZ-2819.',
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        $compactionUrl = "/memory/project/dezeen/compactions/{$compaction->id}";

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->andReturn(json_encode([
            'summary_markdown' => "# WM\n## Current Focus\n- Ship DEZ-2819 [1].",
            'structured_sections' => array_fill_keys(
                ['Current Focus', 'Active Priorities', 'Recent Changes', 'Open Questions', 'Risks / Blockers', 'Next Actions', 'Latest Signals', 'Source Notes'],
                [['text' => 'Ship DEZ-2819 [1].', 'importance' => 1, 'fallback_mode' => 'direct',
                  'citations' => [['type' => 'compaction', 'url' => $compactionUrl, 'label' => 'compaction:meeting']]]]
            ),
            'references' => [['type' => 'compaction', 'url' => $compactionUrl, 'label' => 'compaction:meeting']],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        /** @var WorkingMemoryBuilderService $builder */
        $builder = app(WorkingMemoryBuilderService::class);
        $version = $builder->buildIncremental($user->id, 'project', 'dezeen');

        $diagnostics = $version->build_diagnostics_json;
        $this->assertIsArray($diagnostics);
        $this->assertArrayHasKey('compaction_inputs_count', $diagnostics);
        $this->assertArrayHasKey('compaction_subtypes_used', $diagnostics);
        $this->assertArrayHasKey('raw_thought_inputs_count', $diagnostics);
        $this->assertArrayHasKey('compaction_coverage_ratio', $diagnostics);

        $this->assertSame(1, $diagnostics['compaction_inputs_count']);
        $this->assertContains('meeting', $diagnostics['compaction_subtypes_used']);
        $this->assertGreaterThanOrEqual(1, $diagnostics['raw_thought_inputs_count']);
        $this->assertGreaterThan(0.0, $diagnostics['compaction_coverage_ratio']);
        $this->assertLessThanOrEqual(1.0, $diagnostics['compaction_coverage_ratio']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
