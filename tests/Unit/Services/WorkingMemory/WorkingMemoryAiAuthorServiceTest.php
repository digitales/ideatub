<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Composer\WorkingMemoryComposerPromptBuilder;
use App\Services\WorkingMemory\WorkingMemoryAiAuthorService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryAiAuthorServiceTest extends TestCase
{
    #[Test]
    public function it_calls_openrouter_with_composer_prompt_and_returns_parsed_json(): void
    {
        $promptBuilder = new WorkingMemoryComposerPromptBuilder;
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')
            ->once()
            ->with(Mockery::on(fn ($prompt) => str_contains((string) $prompt, '## Working memory composition task')))
            ->andReturn(json_encode([
                'summary_markdown' => "# Working memory\n## Current Focus\n- Ship DEZ-2819 fix.",
                'structured_sections' => [
                    'Current Focus' => [[
                        'text' => 'Ship DEZ-2819 fix.',
                        'importance' => 1,
                        'fallback_mode' => 'direct',
                        'citations' => [['type' => 'thought', 'url' => '/thoughts/t1', 'label' => 'DEZ-2819']],
                    ]],
                ],
                'references' => [['type' => 'thought', 'url' => '/thoughts/t1', 'label' => 'DEZ-2819']],
            ]));

        $service = new WorkingMemoryAiAuthorService($promptBuilder, $openRouter);

        $result = $service->authorFromEvidence([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [[
                'thought_id' => 't1',
                'content' => 'Need to ship DEZ-2819.',
                'created_at' => '2026-05-07T09:00:00Z',
                'references' => [['type' => 'thought', 'url' => '/thoughts/t1', 'label' => 'DEZ-2819']],
            ]],
            'compactions' => [],
            'section_candidates' => [],
            'section_bundles' => [],
        ]);

        $this->assertArrayHasKey('summary_markdown', $result);
        $this->assertArrayHasKey('structured_sections', $result);
        $this->assertArrayHasKey('references', $result);
        $this->assertSame('Ship DEZ-2819 fix.', $result['structured_sections']['Current Focus'][0]['text']);
    }

    #[Test]
    public function it_falls_back_to_empty_sections_when_model_returns_invalid_json(): void
    {
        $promptBuilder = new WorkingMemoryComposerPromptBuilder;
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->andReturn('not json');

        $service = new WorkingMemoryAiAuthorService($promptBuilder, $openRouter);

        $result = $service->authorFromEvidence([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [],
            'compactions' => [],
            'section_candidates' => [],
            'section_bundles' => [],
        ]);

        $this->assertSame('', $result['summary_markdown']);
        $this->assertSame([], $result['references']);
        $this->assertArrayHasKey('Current Focus', $result['structured_sections']);
        $this->assertSame([], $result['structured_sections']['Current Focus']);
    }

    #[Test]
    public function author_from_evidence_returns_structured_section_items_with_required_schema(): void
    {
        $promptBuilder = new WorkingMemoryComposerPromptBuilder;
        $openRouter = Mockery::mock(OpenRouterService::class);
        $payload = $this->sampleComposerPayloadWithAllSections('Ship working-memory section coverage update.');
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode($payload));

        $service = new WorkingMemoryAiAuthorService($promptBuilder, $openRouter);

        $result = $service->authorFromEvidence([
            'scope_type' => 'global',
            'scope_key' => 'global',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [
                [
                    'thought_id' => 't1',
                    'content' => 'Ship working-memory section coverage update.',
                    'created_at' => '2026-05-06T12:00:00Z',
                    'references' => [
                        [
                            'type' => 'thought',
                            'url' => 'https://ideatub.test/thoughts/1',
                            'label' => '1',
                        ],
                    ],
                ],
            ],
            'section_candidates' => [
                'Current Focus' => ['Ship working-memory section coverage update.'],
            ],
            'section_bundles' => [],
            'compactions' => [],
        ]);

        $requiredSections = [
            'Current Focus',
            'Active Priorities',
            'Recent Changes',
            'Open Questions',
            'Risks / Blockers',
            'Next Actions',
            'Latest Signals',
            'Source Notes',
        ];

        foreach ($requiredSections as $section) {
            $this->assertArrayHasKey($section, $result['structured_sections']);

            foreach ($result['structured_sections'][$section] as $item) {
                $this->assertIsArray($item);
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('text', $item);
                $this->assertArrayHasKey('importance', $item);
                $this->assertArrayHasKey('fallback_mode', $item);
                $this->assertArrayHasKey('citations', $item);
                $this->assertContains($item['fallback_mode'], ['direct', 'section_bundle']);
            }
        }
    }

    #[Test]
    public function author_from_evidence_preserves_model_direct_and_section_bundle_modes_and_summary_markdown(): void
    {
        $promptBuilder = new WorkingMemoryComposerPromptBuilder;
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => "# Working memory synthesis\n\n## Current Focus\n- Direct coverage item\n- Bundle-backed coverage item",
            'structured_sections' => [
                'Current Focus' => [
                    [
                        'text' => 'Direct coverage item',
                        'importance' => 1,
                        'fallback_mode' => 'direct',
                        'citations' => [
                            ['type' => 'thought', 'url' => 'https://ideatub.test/thoughts/10', 'label' => '10'],
                        ],
                    ],
                    [
                        'text' => 'Bundle-backed coverage item',
                        'importance' => 1,
                        'fallback_mode' => 'section_bundle',
                        'citations' => [
                            ['type' => 'source', 'url' => 'docs/superpowers/specs/fallback.md', 'label' => 'fallback.md'],
                        ],
                    ],
                ],
                'Active Priorities' => [[
                    'text' => 'Operational priorities row.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/p1', 'label' => 'P']],
                ]],
                'Recent Changes' => [[
                    'text' => 'Recent change row.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/r1', 'label' => 'R']],
                ]],
                'Open Questions' => [[
                    'text' => 'Open question row?',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/q1', 'label' => 'Q']],
                ]],
                'Risks / Blockers' => [[
                    'text' => 'Risk row.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/risk1', 'label' => 'RK']],
                ]],
                'Next Actions' => [[
                    'text' => 'Next action row.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/n1', 'label' => 'N']],
                ]],
                'Latest Signals' => [[
                    'text' => 'Latest signal row.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/l1', 'label' => 'L']],
                ]],
                'Source Notes' => [[
                    'text' => 'Source note row.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'source', 'url' => 'docs/superpowers/specs/wm.md', 'label' => 'wm.md']],
                ]],
            ],
            'references' => [
                ['type' => 'thought', 'url' => 'https://ideatub.test/thoughts/10', 'label' => '10'],
            ],
        ]));

        $service = new WorkingMemoryAiAuthorService($promptBuilder, $openRouter);

        $result = $service->authorFromEvidence([
            'scope_type' => 'global',
            'scope_key' => 'global',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [
                [
                    'thought_id' => 't10',
                    'content' => 'Direct coverage item',
                    'created_at' => '2026-05-07T09:00:00Z',
                    'references' => [
                        [
                            'type' => 'thought',
                            'url' => 'https://ideatub.test/thoughts/10',
                            'label' => '10',
                        ],
                    ],
                ],
            ],
            'section_candidates' => [
                'Current Focus' => [
                    'Direct coverage item',
                    'Bundle-backed coverage item',
                ],
                'Active Priorities' => ['Uncited candidate should be dropped'],
            ],
            'section_bundles' => [
                'Current Focus' => [
                    [
                        'type' => 'source',
                        'url' => 'docs/superpowers/specs/fallback.md',
                        'label' => 'fallback.md',
                    ],
                ],
            ],
            'compactions' => [],
        ]);

        $focusItems = $result['structured_sections']['Current Focus'];
        $this->assertCount(2, $focusItems);
        $this->assertSame('Direct coverage item', $focusItems[0]['text']);
        $this->assertSame('direct', $focusItems[0]['fallback_mode']);
        $this->assertSame('thought', $focusItems[0]['citations'][0]['type']);

        $this->assertSame('Bundle-backed coverage item', $focusItems[1]['text']);
        $this->assertSame('section_bundle', $focusItems[1]['fallback_mode']);
        $this->assertSame('source', $focusItems[1]['citations'][0]['type']);

        $this->assertStringContainsString('- Direct coverage item', $result['summary_markdown']);
        $this->assertStringContainsString('- Bundle-backed coverage item', $result['summary_markdown']);
    }

    #[Test]
    public function duplicate_normalized_content_keeps_model_citation_bundle_on_single_item(): void
    {
        $promptBuilder = new WorkingMemoryComposerPromptBuilder;
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => '# Summary',
            'structured_sections' => [
                'Current Focus' => [[
                    'text' => 'Duplicate key signal',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [
                        ['type' => 'thought', 'url' => 'https://ideatub.test/thoughts/21', 'label' => '21'],
                        ['type' => 'source', 'url' => 'docs/superpowers/specs/best.md', 'label' => 'best.md'],
                    ],
                ]],
                'Active Priorities' => [[
                    'text' => 'P',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/p', 'label' => 'p']],
                ]],
                'Recent Changes' => [[
                    'text' => 'R',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/r', 'label' => 'r']],
                ]],
                'Open Questions' => [[
                    'text' => 'Q?',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/q', 'label' => 'q']],
                ]],
                'Risks / Blockers' => [[
                    'text' => 'Risk',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/rb', 'label' => 'rb']],
                ]],
                'Next Actions' => [[
                    'text' => 'Next',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/n', 'label' => 'n']],
                ]],
                'Latest Signals' => [[
                    'text' => 'Sig',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/s', 'label' => 's']],
                ]],
                'Source Notes' => [[
                    'text' => 'Note',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'source', 'url' => 'docs/note.md', 'label' => 'note']],
                ]],
            ],
            'references' => [],
        ]));

        $service = new WorkingMemoryAiAuthorService($promptBuilder, $openRouter);

        $result = $service->authorFromEvidence([
            'scope_type' => 'global',
            'scope_key' => 'global',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [
                [
                    'thought_id' => 't21a',
                    'content' => 'Duplicate key signal',
                    'created_at' => '2026-05-07T08:00:00Z',
                    'references' => [
                        [
                            'type' => 'thought',
                            'url' => 'https://ideatub.test/thoughts/21',
                            'label' => '21',
                        ],
                        [
                            'type' => 'source',
                            'url' => 'docs/superpowers/specs/best.md',
                            'label' => 'best.md',
                        ],
                    ],
                ],
                [
                    'thought_id' => 't21b',
                    'content' => 'Duplicate   key signal',
                    'created_at' => '2026-05-07T08:01:00Z',
                    'references' => [
                        [
                            'type' => 'source',
                            'url' => 'docs/superpowers/specs/weaker.md',
                            'label' => 'weaker.md',
                        ],
                    ],
                ],
            ],
            'section_candidates' => [
                'Current Focus' => ['Duplicate key signal'],
            ],
            'section_bundles' => [],
            'compactions' => [],
        ]);

        $focusItem = $result['structured_sections']['Current Focus'][0];
        $this->assertSame('direct', $focusItem['fallback_mode']);
        $this->assertCount(2, $focusItem['citations']);
        $this->assertSame('thought', $focusItem['citations'][0]['type']);
        $this->assertSame('https://ideatub.test/thoughts/21', $focusItem['citations'][0]['url']);
        $this->assertSame('source', $focusItem['citations'][1]['type']);
        $this->assertSame('docs/superpowers/specs/best.md', $focusItem['citations'][1]['url']);
    }

    #[Test]
    public function model_supplied_citation_order_is_preserved_in_normalized_items(): void
    {
        $promptBuilder = new WorkingMemoryComposerPromptBuilder;
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => '# Summary',
            'structured_sections' => [
                'Current Focus' => [[
                    'text' => 'Ordering verification item',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [
                        ['type' => 'thought', 'url' => 'https://ideatub.test/thoughts/22', 'label' => '22'],
                        ['type' => 'source', 'url' => 'docs/superpowers/specs/order.md', 'label' => 'order.md'],
                    ],
                ]],
                'Active Priorities' => [[
                    'text' => 'P',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/p', 'label' => 'p']],
                ]],
                'Recent Changes' => [[
                    'text' => 'R',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/r', 'label' => 'r']],
                ]],
                'Open Questions' => [[
                    'text' => 'Q?',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/q', 'label' => 'q']],
                ]],
                'Risks / Blockers' => [[
                    'text' => 'Risk',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/rb', 'label' => 'rb']],
                ]],
                'Next Actions' => [[
                    'text' => 'Next',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/n', 'label' => 'n']],
                ]],
                'Latest Signals' => [[
                    'text' => 'Sig',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => '/thoughts/s', 'label' => 's']],
                ]],
                'Source Notes' => [[
                    'text' => 'Note',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'source', 'url' => 'docs/note.md', 'label' => 'note']],
                ]],
            ],
            'references' => [],
        ]));

        $service = new WorkingMemoryAiAuthorService($promptBuilder, $openRouter);

        $result = $service->authorFromEvidence([
            'scope_type' => 'global',
            'scope_key' => 'global',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [
                [
                    'thought_id' => 't22',
                    'content' => 'Ordering verification item',
                    'created_at' => '2026-05-07T09:30:00Z',
                    'references' => [
                        [
                            'type' => 'source',
                            'url' => 'docs/superpowers/specs/order.md',
                            'label' => 'order.md',
                        ],
                        [
                            'type' => 'thought',
                            'url' => 'https://ideatub.test/thoughts/22',
                            'label' => '22',
                        ],
                    ],
                ],
            ],
            'section_candidates' => [
                'Current Focus' => ['Ordering verification item'],
            ],
            'section_bundles' => [],
            'compactions' => [],
        ]);

        $focusItem = $result['structured_sections']['Current Focus'][0];
        $this->assertSame('direct', $focusItem['fallback_mode']);
        $this->assertSame('thought', $focusItem['citations'][0]['type']);
        $this->assertSame('source', $focusItem['citations'][1]['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleComposerPayloadWithAllSections(string $focusText): array
    {
        $mk = static fn (string $text, string $mode = 'direct'): array => [
            'text' => $text,
            'importance' => 1,
            'fallback_mode' => $mode,
            'citations' => [
                ['type' => 'thought', 'url' => 'https://ideatub.test/thoughts/1', 'label' => '1'],
            ],
        ];

        return [
            'summary_markdown' => '# Working memory synthesis',
            'structured_sections' => [
                'Current Focus' => [$mk($focusText)],
                'Active Priorities' => [$mk('Confirm section item schema and citation shape.')],
                'Recent Changes' => [$mk('Added structured section item support.')],
                'Open Questions' => [$mk('Should AI output include per-item confidence?')],
                'Risks / Blockers' => [$mk('Delay risk if citation links are missing.')],
                'Next Actions' => [$mk('Run deterministic unit tests for section output.')],
                'Latest Signals' => [$mk('2026-05-06T12:00:00Z - Ship working-memory section coverage update.')],
                'Source Notes' => [[
                    'text' => '1 - https://ideatub.test/thoughts/1',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [
                        ['type' => 'thought', 'url' => 'https://ideatub.test/thoughts/1', 'label' => '1'],
                    ],
                ]],
            ],
            'references' => [
                ['type' => 'thought', 'url' => 'https://ideatub.test/thoughts/1', 'label' => '1'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
