<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\WorkingMemory\WorkingMemoryAiAuthorService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryAiAuthorServiceTest extends TestCase
{
    #[Test]
    public function author_from_evidence_returns_structured_section_items_with_required_schema(): void
    {
        $result = app(WorkingMemoryAiAuthorService::class)->authorFromEvidence([
            'signals' => [
                [
                    'content' => 'Ship working-memory section coverage update.',
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
                'Active Priorities' => ['Confirm section item schema and citation shape.'],
                'Recent Changes' => ['Added structured section item support.'],
                'Open Questions' => ['Should AI output include per-item confidence?'],
                'Risks / Blockers' => ['Delay risk if citation links are missing.'],
                'Next Actions' => ['Run deterministic unit tests for section output.'],
                'Latest Signals' => ['2026-05-06T12:00:00Z - Ship working-memory section coverage update.'],
            ],
            'section_bundles' => [
                'Current Focus' => [
                    [
                        'type' => 'source',
                        'url' => 'docs/superpowers/specs/wm.md',
                        'label' => 'wm.md',
                    ],
                ],
                'Active Priorities' => [
                    [
                        'type' => 'source',
                        'url' => 'docs/superpowers/specs/wm.md',
                        'label' => 'wm.md',
                    ],
                ],
                'Recent Changes' => [
                    [
                        'type' => 'source',
                        'url' => 'docs/superpowers/specs/wm.md',
                        'label' => 'wm.md',
                    ],
                ],
                'Open Questions' => [
                    [
                        'type' => 'source',
                        'url' => 'docs/superpowers/specs/wm.md',
                        'label' => 'wm.md',
                    ],
                ],
                'Risks / Blockers' => [
                    [
                        'type' => 'source',
                        'url' => 'docs/superpowers/specs/wm.md',
                        'label' => 'wm.md',
                    ],
                ],
                'Next Actions' => [
                    [
                        'type' => 'source',
                        'url' => 'docs/superpowers/specs/wm.md',
                        'label' => 'wm.md',
                    ],
                ],
                'Latest Signals' => [
                    [
                        'type' => 'source',
                        'url' => 'docs/superpowers/specs/wm.md',
                        'label' => 'wm.md',
                    ],
                ],
            ],
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
    public function author_from_evidence_prefers_direct_citations_then_section_bundle_and_drops_uncited_candidates(): void
    {
        $result = app(WorkingMemoryAiAuthorService::class)->authorFromEvidence([
            'signals' => [
                [
                    'content' => 'Direct coverage item',
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
                'Recent Changes' => [],
                'Open Questions' => [],
                'Risks / Blockers' => [],
                'Next Actions' => [],
                'Latest Signals' => [],
            ],
            'section_bundles' => [
                'Current Focus' => [
                    [
                        'type' => 'source',
                        'url' => 'docs/superpowers/specs/fallback.md',
                        'label' => 'fallback.md',
                    ],
                ],
                'Active Priorities' => [],
                'Recent Changes' => [],
                'Open Questions' => [],
                'Risks / Blockers' => [],
                'Next Actions' => [],
                'Latest Signals' => [],
            ],
        ]);

        $focusItems = $result['structured_sections']['Current Focus'];
        $this->assertCount(2, $focusItems);
        $this->assertSame('Direct coverage item', $focusItems[0]['text']);
        $this->assertSame('direct', $focusItems[0]['fallback_mode']);
        $this->assertSame('thought', $focusItems[0]['citations'][0]['type']);

        $this->assertSame('Bundle-backed coverage item', $focusItems[1]['text']);
        $this->assertSame('section_bundle', $focusItems[1]['fallback_mode']);
        $this->assertSame('source', $focusItems[1]['citations'][0]['type']);

        $prioritiesItems = $result['structured_sections']['Active Priorities'];
        $this->assertGreaterThan(0, count($prioritiesItems));
        $this->assertStringNotContainsString(
            'Uncited candidate should be dropped',
            $prioritiesItems[0]['text']
        );
        $this->assertNotEmpty($prioritiesItems[0]['citations']);

        foreach ($result['structured_sections']['Recent Changes'] as $item) {
            $this->assertNotEmpty($item['citations']);
        }
        foreach ($result['structured_sections']['Open Questions'] as $item) {
            $this->assertNotEmpty($item['citations']);
        }
        foreach ($result['structured_sections']['Latest Signals'] as $item) {
            $this->assertNotEmpty($item['citations']);
        }

        foreach ($focusItems as $item) {
            $this->assertDoesNotMatchRegularExpression('/\[\d+\]/', $item['text']);
        }

        $this->assertStringContainsString('- Direct coverage item', $result['summary_markdown']);
        $this->assertStringContainsString('- Bundle-backed coverage item', $result['summary_markdown']);
        $this->assertStringNotContainsString('Uncited candidate should be dropped', $result['summary_markdown']);
    }

    #[Test]
    public function duplicate_normalized_content_keeps_best_direct_citation_set_instead_of_regressing(): void
    {
        $result = app(WorkingMemoryAiAuthorService::class)->authorFromEvidence([
            'signals' => [
                [
                    'content' => 'Duplicate key signal',
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
                    'content' => 'Duplicate   key signal',
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
                'Active Priorities' => [],
                'Recent Changes' => [],
                'Open Questions' => [],
                'Risks / Blockers' => [],
                'Next Actions' => [],
                'Latest Signals' => [],
            ],
            'section_bundles' => [
                'Current Focus' => [],
                'Active Priorities' => [],
                'Recent Changes' => [],
                'Open Questions' => [],
                'Risks / Blockers' => [],
                'Next Actions' => [],
                'Latest Signals' => [],
            ],
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
    public function direct_mode_citations_are_ordered_thought_then_source(): void
    {
        $result = app(WorkingMemoryAiAuthorService::class)->authorFromEvidence([
            'signals' => [
                [
                    'content' => 'Ordering verification item',
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
                'Active Priorities' => [],
                'Recent Changes' => [],
                'Open Questions' => [],
                'Risks / Blockers' => [],
                'Next Actions' => [],
                'Latest Signals' => [],
            ],
            'section_bundles' => [
                'Current Focus' => [],
                'Active Priorities' => [],
                'Recent Changes' => [],
                'Open Questions' => [],
                'Risks / Blockers' => [],
                'Next Actions' => [],
                'Latest Signals' => [],
            ],
        ]);

        $focusItem = $result['structured_sections']['Current Focus'][0];
        $this->assertSame('direct', $focusItem['fallback_mode']);
        $this->assertSame('thought', $focusItem['citations'][0]['type']);
        $this->assertSame('source', $focusItem['citations'][1]['type']);
    }
}
