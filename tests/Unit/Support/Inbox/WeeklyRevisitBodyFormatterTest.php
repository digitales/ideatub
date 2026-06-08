<?php

namespace Tests\Unit\Support\Inbox;

use App\Support\Inbox\WeeklyRevisitBodyFormatter;
use App\Support\SafeCommonMarkConverter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WeeklyRevisitBodyFormatterTest extends TestCase
{
    #[Test]
    public function strips_markdown_headings_and_collapses_whitespace_in_preview(): void
    {
        $preview = WeeklyRevisitBodyFormatter::formatIdeaPreview("  Messy idea title \n\n## Section\n\n with awkward\tspacing  ");

        $this->assertSame('Messy idea title Section with awkward spacing', $preview);
    }

    #[Test]
    public function limits_long_previews_to_a_scannable_snippet(): void
    {
        $long = str_repeat('word ', 80);

        $preview = WeeklyRevisitBodyFormatter::formatIdeaPreview($long);

        $this->assertLessThanOrEqual(203, mb_strlen($preview));
        $this->assertStringEndsWith('...', $preview);
    }

    #[Test]
    public function sanitize_stored_body_merges_breakout_headings_back_into_bullets(): void
    {
        $body = <<<'MD'
Review these older ideas:
- Lantern Opportunity Research

## Opportunity Summary

One focused opportunity.
- Idea: look into the content migration project
MD;

        $sanitized = WeeklyRevisitBodyFormatter::sanitizeStoredBody($body);

        $this->assertStringContainsString('Review these older ideas:', $sanitized);
        $this->assertStringNotContainsString('##', $sanitized);
        $this->assertStringContainsString('- Lantern Opportunity Research Opportunity Summary One focused opportunity.', $sanitized);
        $this->assertStringContainsString('- Idea: look into the content migration project', $sanitized);

        $html = SafeCommonMarkConverter::toHtml($sanitized);
        $this->assertStringNotContainsString('<h2>', $html);
    }
}
