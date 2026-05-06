<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\WorkingMemory\WorkingMemoryAiAuthorService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryAiAuthorServiceTest extends TestCase
{
    #[Test]
    public function author_from_evidence_uses_fallback_sections_without_dangling_citations_when_references_are_empty(): void
    {
        $result = app(WorkingMemoryAiAuthorService::class)->authorFromEvidence([
            'signals' => [],
        ]);

        $this->assertSame([], $result['references']);

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
        }

        $sectionsWithoutDanglingCitations = [
            'Active Priorities',
            'Risks / Blockers',
            'Next Actions',
            'Latest Signals',
        ];

        foreach ($sectionsWithoutDanglingCitations as $section) {
            foreach ($result['structured_sections'][$section] as $bullet) {
                $this->assertDoesNotMatchRegularExpression('/\[\d+\]/', $bullet);
            }
        }
    }
}
