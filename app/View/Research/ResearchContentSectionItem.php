<?php

namespace App\View\Research;

use App\Models\Thought;

/**
 * One research section row for {@see ResearchContentCommentsViewData} (grid + comment threads).
 */
final class ResearchContentSectionItem
{
    /**
     * @param  array{count: int, label: string}|null  $mobileSummary
     * @param  array<string, mixed>|null  $mobileThreadInclude
     * @param  array<string, mixed>|null  $sidebarThreadInclude
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $contentHtml,
        public readonly ?Thought $thought,
        public readonly ?array $mobileSummary,
        public readonly ?array $mobileThreadInclude,
        public readonly ?array $sidebarThreadInclude,
    ) {}
}
