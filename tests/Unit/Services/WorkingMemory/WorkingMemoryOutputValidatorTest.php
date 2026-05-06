<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\WorkingMemory\WorkingMemoryOutputValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryOutputValidatorTest extends TestCase
{
    #[Test]
    public function missing_required_sections_fail_validation(): void
    {
        $payload = [
            'summary_markdown' => '# Working memory synthesis',
            'structured_sections' => [
                'Current Focus' => ['Ship the integration endpoint [1]'],
                'Active Priorities' => ['Close open rollout issues [1]'],
            ],
            'references' => [
                ['url' => 'https://example.com/ref-1', 'label' => 'Ref 1'],
            ],
        ];

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload);

        $this->assertFalse($result['ok']);
        $this->assertSame('hard', $result['failure_type']);
        $this->assertStringContainsString('Missing required sections', (string) $result['message']);
    }

    #[Test]
    public function configured_required_sections_are_enforced(): void
    {
        config()->set('working_memory.citation_required_sections', ['Current Focus', 'Team Notes']);

        $payload = $this->validStructuredPayload();
        unset($payload['structured_sections']['Team Notes']);

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload);

        $this->assertFalse($result['ok']);
        $this->assertSame('hard', $result['failure_type']);
        $this->assertStringContainsString('Team Notes', (string) $result['message']);
    }

    #[Test]
    public function configured_required_sections_allow_excluding_source_notes(): void
    {
        config()->set('working_memory.citation_required_sections', [
            'Current Focus',
            'Active Priorities',
            'Recent Changes',
            'Open Questions',
            'Risks / Blockers',
            'Next Actions',
            'Latest Signals',
        ]);

        $payload = $this->validStructuredPayload();
        unset($payload['structured_sections']['Source Notes']);

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['failure_type']);
    }

    #[Test]
    public function unresolvable_references_fail_validation(): void
    {
        $payload = $this->validStructuredPayload();
        $payload['structured_sections']['Active Priorities'][0]['citations'] = [[
            'type' => 'source',
            'label' => 'Unsafe link',
            'url' => 'javascript:alert(1)',
        ]];

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload);

        $this->assertFalse($result['ok']);
        $this->assertSame('hard', $result['failure_type']);
        $this->assertContains('invalid_link', $result['diagnostics']['reason_codes'] ?? []);
    }

    #[Test]
    public function malformed_references_fail_hard(): void
    {
        $payload = $this->validStructuredPayload();
        $payload['references'] = [
            ['url' => '', 'label' => 'Spec notes'],
        ];

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload);

        $this->assertFalse($result['ok']);
        $this->assertSame('hard', $result['failure_type']);
        $this->assertStringContainsString('References must include non-empty url and label', (string) $result['message']);
        $this->assertNull($result['coveragePercent']);
    }

    #[Test]
    public function low_coverage_returns_soft_failure(): void
    {
        $payload = $this->validStructuredPayload();

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload, 1.01);

        $this->assertFalse($result['ok']);
        $this->assertSame('soft', $result['failure_type']);
        $this->assertSame(100.0, $result['coveragePercent']);
        $this->assertContains('coverage_below_threshold', $result['diagnostics']['reason_codes'] ?? []);
    }

    #[Test]
    public function exact_coverage_threshold_boundary_passes_validation(): void
    {
        $payload = $this->validStructuredPayload();

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload, 1.0);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['failure_type']);
        $this->assertSame(100.0, $result['coveragePercent']);
    }

    #[Test]
    public function diagnostics_include_required_and_cited_item_counts(): void
    {
        $payload = $this->validStructuredPayload();

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload);

        $this->assertTrue($result['ok']);
        $this->assertSame(8, $result['diagnostics']['required_items'] ?? null);
        $this->assertSame(8, $result['diagnostics']['cited_items'] ?? null);
        $this->assertSame([], $result['diagnostics']['reason_codes'] ?? null);
    }

    #[Test]
    public function valid_payload_passes_validation(): void
    {
        $result = app(WorkingMemoryOutputValidator::class)->validate($this->validStructuredPayload());

        $this->assertTrue($result['ok']);
        $this->assertNull($result['failure_type']);
        $this->assertNull($result['message']);
        $this->assertSame(100.0, $result['coveragePercent']);
    }

    #[Test]
    public function missing_item_citation_in_required_section_fails_hard(): void
    {
        $payload = $this->validStructuredPayload();
        $payload['structured_sections']['Next Actions'][0]['citations'] = [];

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload, 1.0);

        $this->assertFalse($result['ok']);
        $this->assertSame('hard', $result['failure_type']);
        $this->assertContains('missing_citation', $result['diagnostics']['reason_codes'] ?? []);
    }

    #[Test]
    public function bundle_fallback_item_counts_as_cited_when_urls_are_valid(): void
    {
        $payload = $this->validStructuredPayload();
        $payload['structured_sections']['Risks / Blockers'][0]['fallback_mode'] = 'section_bundle';
        $payload['structured_sections']['Risks / Blockers'][0]['citations'] = [[
            'type' => 'bundle',
            'label' => 'Risk bundle',
            'url' => 'https://example.com/bundles/risk',
            'source_ref' => 'bundle:risk',
        ]];

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload, 1.0);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['failure_type']);
        $this->assertSame(100.0, $result['coveragePercent']);
    }

    /**
     * @return array{
     *     summary_markdown: string,
     *     structured_sections: array<string, array<int, string>>,
     *     references: array<int, array{url: string, label: string}>
     * }
     */
    /**
     * @return array{
     *     summary_markdown: string,
     *     structured_sections: array<string, array<int, array<string, mixed>>>,
     *     references: array<int, array{url: string, label: string}>
     * }
     */
    private function validStructuredPayload(): array
    {
        $makeItem = function (string $text, string $url, string $label = 'Spec notes'): array {
            return [
                'id' => 'item-'.md5($text),
                'text' => $text,
                'importance' => 50,
                'fallback_mode' => 'direct',
                'citations' => [[
                    'type' => 'source',
                    'label' => $label,
                    'url' => $url,
                    'source_ref' => 'source:spec-notes',
                ]],
            ];
        };

        return [
            'summary_markdown' => '# Working memory synthesis',
            'structured_sections' => [
                'Current Focus' => [$makeItem('Roll out the AI-authored structure endpoint', 'https://example.com/ref-1')],
                'Active Priorities' => [$makeItem('Stabilize section rendering for detail cards', 'https://example.com/ref-1')],
                'Recent Changes' => [$makeItem('Added deterministic authoring scaffold', 'https://example.com/ref-1')],
                'Open Questions' => [$makeItem('Do we tighten citation threshold for project scopes?', 'https://example.com/ref-1')],
                'Risks / Blockers' => [$makeItem('Validator integration hook is still pending', 'https://example.com/ref-1')],
                'Next Actions' => [$makeItem('Wire validator into builder flow and measure coverage', 'https://example.com/ref-1')],
                'Latest Signals' => [$makeItem('Research packet landed with new requirements', 'https://example.com/ref-1')],
                'Source Notes' => [$makeItem('Spec notes and implementation links', 'https://example.com/ref-1')],
            ],
            'references' => [
                ['url' => 'https://example.com/ref-1', 'label' => 'Spec notes'],
            ],
        ];
    }
}
