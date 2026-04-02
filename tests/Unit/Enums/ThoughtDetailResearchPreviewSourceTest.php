<?php

namespace Tests\Unit\Enums;

use App\Enums\ThoughtDetailResearchPreviewSource;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ThoughtDetailResearchPreviewSourceTest extends TestCase
{
    public static function contextProvider(): array
    {
        return [
            'email root' => [ThoughtDetailResearchPreviewSource::Email, 'email_research_preview_root', 'email_research_preview_section'],
            'video root' => [ThoughtDetailResearchPreviewSource::Video, 'video_research_preview_root', 'video_research_preview_section'],
        ];
    }

    #[DataProvider('contextProvider')]
    public function test_demo_safe_markdown_contexts(
        ThoughtDetailResearchPreviewSource $source,
        string $expectedRoot,
        string $expectedSection,
    ): void {
        $this->assertSame($expectedRoot, $source->demoSafeMarkdownRootContext());
        $this->assertSame($expectedSection, $source->demoSafeMarkdownSectionContext());
    }
}
