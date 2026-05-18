<?php

namespace App\DataTransferObjects;

/**
 * One actionable row in the morning brief (draft, inbox, revisit, project).
 */
readonly class MorningBriefCardData
{
    public function __construct(
        public string $kind,
        public string $label,
        public string $title,
        public ?string $subtitle,
        public string $href,
        public ?string $draftId = null,
    ) {}
}
