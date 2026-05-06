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
    public function unresolvable_references_fail_validation(): void
    {
        $payload = $this->validPayload();
        $payload['structured_sections']['Active Priorities'] = ['Rollout blocked by unresolved migration [9]'];

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload);

        $this->assertFalse($result['ok']);
        $this->assertSame('hard', $result['failure_type']);
        $this->assertStringContainsString('Unresolvable references', (string) $result['message']);
    }

    #[Test]
    public function malformed_references_fail_hard(): void
    {
        $payload = $this->validPayload();
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
        $payload = $this->validPayload();
        $payload['structured_sections']['Active Priorities'] = ['No citation in this bullet'];
        $payload['structured_sections']['Risks / Blockers'] = ['Potential delay due to dependency updates'];
        $payload['structured_sections']['Next Actions'] = ['Validate rollout checklist this afternoon'];
        $payload['structured_sections']['Latest Signals'] = ['Latest sync was this morning'];
        $payload['structured_sections']['Current Focus'] = ['Still cited but out of coverage scope [1]'];
        $payload['structured_sections']['Recent Changes'] = ['Still cited but out of coverage scope [1]'];
        $payload['structured_sections']['Open Questions'] = ['Out-of-scope citation [1]?'];
        $payload['structured_sections']['Source Notes'] = ['[1] Spec notes - https://example.com/ref-1'];

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload, 0.90);

        $this->assertFalse($result['ok']);
        $this->assertSame('soft', $result['failure_type']);
        $this->assertNotNull($result['coveragePercent']);
        $this->assertLessThan(90.0, (float) $result['coveragePercent']);
    }

    #[Test]
    public function exact_coverage_threshold_boundary_passes_validation(): void
    {
        $payload = $this->validPayload();
        $payload['structured_sections']['Latest Signals'] = ['Latest sync was this morning'];

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload, 0.75);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['failure_type']);
        $this->assertSame(75.0, $result['coveragePercent']);
    }

    #[Test]
    public function citation_coverage_is_computed_from_key_sections_only(): void
    {
        $payload = $this->validPayload();
        $payload['structured_sections']['Current Focus'] = ['No citation here by design'];
        $payload['structured_sections']['Recent Changes'] = ['No citation here either'];
        $payload['structured_sections']['Open Questions'] = ['Should this stay uncited?'];
        $payload['structured_sections']['Source Notes'] = ['[1] Spec notes - https://example.com/ref-1'];

        $result = app(WorkingMemoryOutputValidator::class)->validate($payload, 1.0);

        $this->assertTrue($result['ok']);
        $this->assertSame(100.0, $result['coveragePercent']);
    }

    #[Test]
    public function valid_payload_passes_validation(): void
    {
        $result = app(WorkingMemoryOutputValidator::class)->validate($this->validPayload());

        $this->assertTrue($result['ok']);
        $this->assertNull($result['failure_type']);
        $this->assertNull($result['message']);
        $this->assertSame(100.0, $result['coveragePercent']);
    }

    /**
     * @return array{
     *     summary_markdown: string,
     *     structured_sections: array<string, array<int, string>>,
     *     references: array<int, array{url: string, label: string}>
     * }
     */
    private function validPayload(): array
    {
        return [
            'summary_markdown' => '# Working memory synthesis',
            'structured_sections' => [
                'Current Focus' => ['Roll out the AI-authored structure endpoint [1]'],
                'Active Priorities' => ['Stabilize section rendering for detail cards [1]'],
                'Recent Changes' => ['Added deterministic authoring scaffold [1]'],
                'Open Questions' => ['Do we tighten citation threshold for project scopes? [1]'],
                'Risks / Blockers' => ['Validator integration hook is still pending [1]'],
                'Next Actions' => ['Wire validator into builder flow and measure coverage [1]'],
                'Latest Signals' => ['Research packet landed with new requirements [1]'],
                'Source Notes' => ['[1] Spec notes - https://example.com/ref-1'],
            ],
            'references' => [
                ['url' => 'https://example.com/ref-1', 'label' => 'Spec notes'],
            ],
        ];
    }
}
