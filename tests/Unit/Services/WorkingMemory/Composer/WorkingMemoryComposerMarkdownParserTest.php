<?php

namespace Tests\Unit\Services\WorkingMemory\Composer;

use App\Services\WorkingMemory\Composer\WorkingMemoryComposerMarkdownParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryComposerMarkdownParserTest extends TestCase
{
    private const SECTIONS = [
        'Current Focus',
        'Active Priorities',
        'Recent Changes',
        'Open Questions',
        'Risks / Blockers',
        'Next Actions',
        'Latest Signals',
        'Source Notes',
    ];

    #[Test]
    public function it_parses_numbered_lists_under_section_headings(): void
    {
        $raw = <<<'MD'
## Current Focus
The current focus is finalizing the April 14th meeting.

## Active Priorities
1. Confirm the meeting time for April 14th.
2. Monitor the ISIO cost modeller project.
MD;

        $parsed = WorkingMemoryComposerMarkdownParser::parse($raw, self::SECTIONS);

        $this->assertNotNull($parsed);
        $this->assertStringContainsString('## Current Focus', $parsed['summary_markdown']);
        $this->assertSame('The current focus is finalizing the April 14th meeting.', $parsed['structured_sections']['Current Focus'][0]['text']);
        $this->assertCount(2, $parsed['structured_sections']['Active Priorities']);
        $this->assertSame('Confirm the meeting time for April 14th.', $parsed['structured_sections']['Active Priorities'][0]['text']);
    }

    #[Test]
    public function it_returns_null_for_plain_text_without_section_headings(): void
    {
        $this->assertNull(WorkingMemoryComposerMarkdownParser::parse('not json', self::SECTIONS));
    }

    #[Test]
    public function it_returns_null_when_headings_do_not_match_required_sections(): void
    {
        $raw = "## Random\n\nSome content.";

        $this->assertNull(WorkingMemoryComposerMarkdownParser::parse($raw, self::SECTIONS));
    }
}
