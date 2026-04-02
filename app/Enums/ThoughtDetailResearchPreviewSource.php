<?php

namespace App\Enums;

/**
 * Distinguishes demo-safe markdown obfuscation contexts for linked research previews on thought detail.
 */
enum ThoughtDetailResearchPreviewSource
{
    case Email;
    case Video;

    public function demoSafeMarkdownRootContext(): string
    {
        return match ($this) {
            self::Email => 'email_research_preview_root',
            self::Video => 'video_research_preview_root',
        };
    }

    public function demoSafeMarkdownSectionContext(): string
    {
        return match ($this) {
            self::Email => 'email_research_preview_section',
            self::Video => 'video_research_preview_section',
        };
    }
}
