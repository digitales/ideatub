<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use App\Services\WorkingMemory\WorkingMemoryConsolidationWindowResolver;
use App\Services\WorkingMemory\WorkingMemoryOutputValidator;
use App\Services\WorkingMemory\WorkingMemoryScopeNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class WorkingMemoryBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_authored_sections_and_references_for_global_scope(): void
    {
        config([
            'features.working_memory_ai_authored' => true,
            'working_memory.authoring_enabled' => true,
            'working_memory.citation_min_coverage' => 0.75,
        ]);

        $user = User::factory()->create();

        Thought::factory()->count(3)->create([
            'user_id' => $user->id,
            'content' => 'Plan API cleanup and finalize deployment checklist.',
            'metadata' => ['tags' => ['api', 'cleanup']],
        ]);

        $version = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'global');

        $this->assertSame('validated', $version->authoring_status);
        $this->assertNull($version->validation_error);
        $this->assertIsArray($version->structured_sections_json);
        $this->assertArrayHasKey('Current Focus', $version->structured_sections_json);
        $focusItem = $version->structured_sections_json['Current Focus'][0];
        $this->assertIsArray($focusItem);
        foreach (['id', 'text', 'importance', 'fallback_mode', 'citations'] as $key) {
            $this->assertArrayHasKey($key, $focusItem);
        }
        $this->assertContains($focusItem['fallback_mode'], ['direct', 'section_bundle']);
        $this->assertIsArray($focusItem['citations']);
        $this->assertIsArray($version->references_json);
        $this->assertNotEmpty($version->references_json);
        $this->assertIsArray($version->section_references_json);
        $this->assertArrayHasKey('Current Focus', $version->section_references_json);
        $this->assertNotEmpty($version->section_references_json['Current Focus']);
        $sectionReference = $version->section_references_json['Current Focus'][0];
        $this->assertIsArray($sectionReference);
        $this->assertArrayHasKey('type', $sectionReference);
        $this->assertArrayHasKey('url', $sectionReference);
        $this->assertArrayHasKey('label', $sectionReference);
        $this->assertSame('stream_filter', $sectionReference['type']);
        $this->assertStringContainsString('/stream?', (string) $sectionReference['url']);
        $this->assertStringContainsString('section=', (string) $sectionReference['url']);
        $this->assertStringContainsString(rawurlencode('Current Focus'), (string) $sectionReference['url']);
        $streamFilterUrls = collect($version->section_references_json)
            ->map(function (array $references): ?string {
                $first = $references[0] ?? null;
                if (! is_array($first) || ($first['type'] ?? null) !== 'stream_filter') {
                    return null;
                }

                return (string) ($first['url'] ?? '');
            })
            ->filter()
            ->values();
        $this->assertNotEmpty($streamFilterUrls);
        $this->assertCount(
            $streamFilterUrls->count(),
            $streamFilterUrls->unique()->values(),
            'Each section should have its own stream filter URL.'
        );
        $this->assertGreaterThan(0, (float) ($version->citation_coverage ?? 0));
        $this->assertIsArray($version->build_diagnostics_json);
        $this->assertArrayHasKey('required_items', $version->build_diagnostics_json);
        $this->assertArrayHasKey('cited_items', $version->build_diagnostics_json);
        $this->assertArrayHasKey('reason_codes', $version->build_diagnostics_json);
        $this->assertSame([], $version->build_diagnostics_json['reason_codes']);

        $thoughts = Thought::query()
            ->where('user_id', $user->id)
            ->visibleInStream()
            ->with('projects:id')
            ->orderByDesc('created_at')
            ->get();
        $expectedConfidence = app(WorkingMemoryAssembler::class)->assemblePayload($thoughts)['confidence_score'];
        $this->assertEqualsWithDelta($expectedConfidence, (float) $version->confidence_score, 0.01);
        $this->assertNotEquals(
            round((float) $version->citation_coverage, 2),
            round((float) $version->confidence_score, 2),
            'confidence_score must track legacy heuristic, not citation coverage percent'
        );

        $this->assertStringContainsString('# Working memory synthesis', $version->summary_markdown);
    }

    #[Test]
    public function it_persists_structured_output_for_project_tag_and_insights_scopes(): void
    {
        config([
            'features.working_memory_ai_authored' => true,
            'working_memory.authoring_enabled' => true,
            'working_memory.citation_min_coverage' => 0.75,
        ]);

        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Project scope signal for my-app.',
            'source_metadata' => ['project' => 'my-app'],
            'metadata' => ['tags' => ['project', 'delivery']],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Tag scope signal for ai improvements.',
            'metadata' => ['tags' => ['ai', 'memory']],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Insights scope research note with pending question?',
            'metadata' => ['type' => 'research', 'tags' => ['insights', 'research']],
        ]);

        $projectVersion = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'project', 'my-app');
        $tagVersion = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'tag', 'ai');
        $insightsVersion = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'insights', 'global');

        foreach ([$projectVersion, $tagVersion, $insightsVersion] as $version) {
            $this->assertSame('validated', $version->authoring_status);
            $this->assertIsArray($version->structured_sections_json);
            $this->assertArrayHasKey('Current Focus', $version->structured_sections_json);
            $this->assertIsArray($version->references_json);
            $this->assertNotEmpty($version->references_json);
            $this->assertIsArray($version->section_references_json);
            $this->assertArrayHasKey('Current Focus', $version->section_references_json);
        }
    }

    #[Test]
    public function soft_validation_failure_falls_back_to_legacy_summary_and_tracks_validation_error(): void
    {
        config([
            'features.working_memory_ai_authored' => true,
            'working_memory.authoring_enabled' => true,
            'working_memory.citation_min_coverage' => 1.01,
        ]);

        $user = User::factory()->create();

        Thought::factory()->count(3)->create([
            'user_id' => $user->id,
            'content' => 'Plan API cleanup and finalize deployment checklist.',
            'metadata' => ['tags' => ['api', 'cleanup']],
        ]);

        $version = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'global');

        $this->assertSame('fallback', $version->authoring_status);
        $this->assertNotNull($version->validation_error);
        $this->assertStringContainsString('Citation coverage', (string) $version->validation_error);
        $this->assertNull($version->structured_sections_json);
        $this->assertSame([], $version->references_json ?? []);
        $this->assertSame([], $version->section_references_json ?? []);
        $this->assertNull($version->citation_coverage);
        $this->assertIsArray($version->build_diagnostics_json);
        $this->assertContains(
            'coverage_below_threshold',
            $version->build_diagnostics_json['reason_codes'] ?? []
        );
        $this->assertStringContainsString('## Executive summary', $version->summary_markdown);
    }

    #[Test]
    public function it_preserves_optional_citation_metadata_when_normalizing(): void
    {
        $builder = app(WorkingMemoryBuilderService::class);
        $method = new ReflectionMethod(WorkingMemoryBuilderService::class, 'normalizedCitationRow');
        $method->setAccessible(true);

        /** @var array<string, mixed>|null $row */
        $row = $method->invoke($builder, [
            'type' => 'thought',
            'url' => 'https://example.com/doc',
            'label' => 'Primary source',
            'thought_id' => '019ae6f3-1111-7000-8000-000000000001',
            'source_ref' => 'bundle:risk',
            'confidence' => 0.82,
        ]);

        $this->assertNotNull($row);
        $this->assertSame('thought', $row['type']);
        $this->assertSame('bundle:risk', $row['source_ref']);
        $this->assertSame(0.82, $row['confidence']);
        $this->assertSame('019ae6f3-1111-7000-8000-000000000001', $row['thought_id']);
    }

    #[Test]
    public function it_builds_section_references_with_section_specific_urls_and_deduped_valid_links(): void
    {
        $builder = app(WorkingMemoryBuilderService::class);
        $method = new ReflectionMethod(WorkingMemoryBuilderService::class, 'buildSectionReferences');
        $method->setAccessible(true);

        /** @var array<string, array<int, array<string, mixed>>> $sectionReferences */
        $sectionReferences = $method->invoke(
            $builder,
            [
                'Current Focus' => [[
                    'id' => 'focus-1',
                    'text' => 'Focus item',
                    'importance' => 0,
                    'fallback_mode' => 'direct',
                    'citations' => [
                        ['type' => 'source', 'url' => 'https://example.com/a', 'label' => 'A'],
                        ['type' => 'source', 'url' => 'https://example.com/a', 'label' => 'A'],
                        ['type' => 'source', 'url' => 'javascript:alert(1)', 'label' => 'Bad'],
                    ],
                ]],
                'Next Actions' => [[
                    'id' => 'next-1',
                    'text' => 'Do thing',
                    'importance' => 0,
                    'fallback_mode' => 'direct',
                    'citations' => [],
                ]],
            ],
            [
                ['type' => 'source', 'url' => 'https://fallback.test/doc', 'label' => 'Fallback'],
                ['type' => 'source', 'url' => 'https://fallback.test/doc', 'label' => 'Fallback'],
                ['type' => 'source', 'url' => 'javascript:alert(2)', 'label' => 'Bad fallback'],
            ],
            'project',
            'my-app'
        );

        $this->assertArrayHasKey('Current Focus', $sectionReferences);
        $this->assertArrayHasKey('Next Actions', $sectionReferences);

        $currentFocus = $sectionReferences['Current Focus'];
        $nextActions = $sectionReferences['Next Actions'];

        $this->assertSame('stream_filter', $currentFocus[0]['type']);
        $this->assertSame('stream_filter', $nextActions[0]['type']);
        $this->assertNotSame($currentFocus[0]['url'], $nextActions[0]['url']);
        $this->assertStringContainsString('section='.rawurlencode('Current Focus'), (string) $currentFocus[0]['url']);
        $this->assertStringContainsString('section='.rawurlencode('Next Actions'), (string) $nextActions[0]['url']);

        $this->assertCount(2, $currentFocus);
        $this->assertCount(2, $nextActions);
        $this->assertSame('https://example.com/a', $currentFocus[1]['url']);
        $this->assertSame('https://fallback.test/doc', $nextActions[1]['url']);

        foreach ($sectionReferences as $references) {
            $urls = collect($references)->pluck('url')->all();
            $this->assertCount(count(array_unique($urls)), $urls);
            foreach ($urls as $url) {
                $this->assertStringNotContainsString('javascript:', (string) $url);
            }
        }
    }

    #[Test]
    public function it_validates_section_references_urls_for_safe_relative_paths(): void
    {
        $builder = app(WorkingMemoryBuilderService::class);
        $method = new ReflectionMethod(WorkingMemoryBuilderService::class, 'buildSectionReferences');
        $method->setAccessible(true);

        /** @var array<string, array<int, array<string, mixed>>> $sectionReferences */
        $sectionReferences = $method->invoke(
            $builder,
            [
                'Current Focus' => [[
                    'id' => 'focus-1',
                    'text' => 'Focus item',
                    'importance' => 0,
                    'fallback_mode' => 'direct',
                    'citations' => [
                        ['type' => 'source', 'url' => 'docs/superpowers/specs/example.md', 'label' => 'Local spec'],
                        ['type' => 'source', 'url' => '../.env', 'label' => 'Traversal'],
                        ['type' => 'source', 'url' => 'javascript:alert(1)', 'label' => 'Script'],
                    ],
                ]],
            ],
            [],
            'global',
            'global'
        );

        $this->assertArrayHasKey('Current Focus', $sectionReferences);
        $currentFocus = $sectionReferences['Current Focus'];
        $this->assertNotEmpty($currentFocus);
        $this->assertSame('stream_filter', $currentFocus[0]['type']);
        $urls = collect($currentFocus)->pluck('url')->all();
        $this->assertContains('docs/superpowers/specs/example.md', $urls);
        $this->assertNotContains('../.env', $urls);
        $this->assertNotContains('javascript:alert(1)', $urls);
    }

    #[Test]
    public function validator_hard_validation_failure_preserves_last_known_good_and_degraded_freshness(): void
    {
        config([
            'features.working_memory_ai_authored' => true,
            'working_memory.authoring_enabled' => true,
            'working_memory.citation_min_coverage' => 0.75,
        ]);

        $user = User::factory()->create();

        Thought::factory()->count(3)->create([
            'user_id' => $user->id,
            'content' => 'Baseline corpus for working memory build.',
            'metadata' => ['tags' => ['wm', 'baseline']],
        ]);

        $this->mock(WorkingMemoryOutputValidator::class, function ($mock): void {
            $mock->shouldReceive('validate')
                ->twice()
                ->andReturn(
                    [
                        'ok' => true,
                        'message' => null,
                        'coveragePercent' => 100.0,
                        'failure_type' => null,
                        'diagnostics' => [
                            'required_items' => 8,
                            'cited_items' => 8,
                            'reason_codes' => [],
                        ],
                    ],
                    [
                        'ok' => false,
                        'message' => 'Simulated hard validation failure.',
                        'coveragePercent' => null,
                        'failure_type' => 'hard',
                        'diagnostics' => [
                            'required_items' => 2,
                            'cited_items' => 0,
                            'reason_codes' => ['missing_citation'],
                        ],
                    ],
                );
        });

        $baselineVersion = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'global');

        $versionCountBefore = $baselineVersion->workingMemory->versions()->count();

        $fallbackVersion = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'global');

        $this->assertSame($baselineVersion->id, $fallbackVersion->id);
        $this->assertSame(
            $baselineVersion->id,
            $baselineVersion->workingMemory->fresh()->latest_version_id
        );
        $this->assertSame($versionCountBefore, $baselineVersion->workingMemory->versions()->count());
        $this->assertSame('degraded', $fallbackVersion->workingMemory->fresh()->freshness_state);
    }

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

    #[Test]
    public function ai_hard_failure_returns_last_known_good_version_when_available(): void
    {
        config([
            'features.working_memory_ai_authored' => true,
            'working_memory.authoring_enabled' => true,
            'working_memory.citation_min_coverage' => 0.75,
        ]);

        $user = User::factory()->create();

        Thought::withoutEvents(function () use ($user): void {
            Thought::factory()->count(3)->create([
                'user_id' => $user->id,
                'content' => 'Baseline signal used to seed a known good version.',
                'metadata' => ['tags' => ['baseline', 'working-memory']],
            ]);
        });

        $baselineVersion = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'global');
        $versionCountBeforeFailure = $baselineVersion->workingMemory->versions()->count();

        Thought::query()->where('user_id', $user->id)->delete();

        Thought::withoutEvents(function () use ($user): void {
            Thought::factory()->create([
                'user_id' => $user->id,
                'content' => 'Introduce invalid citation [999] so validator hard-fails.',
                'metadata' => ['tags' => ['baseline', 'working-memory']],
            ]);
        });

        $fallbackVersion = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'global');

        $this->assertSame($baselineVersion->id, $fallbackVersion->id);
        $this->assertSame(
            $baselineVersion->id,
            $baselineVersion->workingMemory->fresh()->latest_version_id
        );
        $this->assertSame($versionCountBeforeFailure, $baselineVersion->workingMemory->versions()->count());
        $this->assertSame('degraded', $fallbackVersion->workingMemory->fresh()->freshness_state);
    }

    #[Test]
    public function ai_hard_failure_bubbles_when_no_prior_version_exists(): void
    {
        config([
            'features.working_memory_ai_authored' => true,
            'working_memory.authoring_enabled' => true,
            'working_memory.citation_min_coverage' => 0.75,
        ]);

        $user = User::factory()->create();

        Thought::withoutEvents(function () use ($user): void {
            Thought::factory()->create([
                'user_id' => $user->id,
                'content' => 'First build is invalid because it includes [999].',
                'metadata' => ['tags' => ['working-memory']],
            ]);
        });

        try {
            app(WorkingMemoryBuilderService::class)
                ->buildConsolidated($user->id, 'global', 'global');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Required section items must include resolvable citations.',
                $e->getMessage()
            );
        }
    }

    #[Test]
    public function validation_runtime_failure_uses_validator_style_semantics_and_keeps_last_known_good_version_as_fallback(): void
    {
        $user = User::factory()->create();

        Thought::factory()->count(3)->create([
            'user_id' => $user->id,
            'content' => 'Baseline signal used to seed a known good version.',
            'metadata' => ['tags' => ['baseline', 'working-memory']],
        ]);

        $baselineVersion = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'global');
        $versionCountBeforeFailure = $baselineVersion->workingMemory->versions()->count();

        $failingAssembler = new class(app(WorkingMemoryScopeNormalizer::class), app(WorkingMemoryConsolidationWindowResolver::class)) extends WorkingMemoryAssembler
        {
            /**
             * @param  Collection<int, Thought>  $thoughts
             */
            public function assemblePayload(Collection $thoughts): array
            {
                throw new RuntimeException('Output validator rejected AI-authored payload.');
            }
        };

        $this->app->instance(WorkingMemoryAssembler::class, $failingAssembler);

        $fallbackVersion = app(WorkingMemoryBuilderService::class)
            ->buildConsolidated($user->id, 'global', 'global');

        $this->assertSame($baselineVersion->id, $fallbackVersion->id);
        $this->assertSame(
            $baselineVersion->id,
            $baselineVersion->workingMemory->fresh()->latest_version_id
        );
        $this->assertSame($versionCountBeforeFailure, $baselineVersion->workingMemory->versions()->count());
        $this->assertSame('degraded', $fallbackVersion->workingMemory->fresh()->freshness_state);
    }
}
