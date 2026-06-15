<?php

namespace App\DataTransferObjects;

/**
 * Full Pulse page payload.
 */
readonly class AttentionOverviewData
{
    /**
     * @param  list<AttentionSectionData>  $sections
     */
    public function __construct(
        public array $sections,
    ) {}

    public function totalCount(): int
    {
        return array_sum(array_map(
            fn (AttentionSectionData $section): int => count($section->items),
            $this->sections,
        ));
    }

    public function isEmpty(): bool
    {
        return $this->totalCount() === 0;
    }
}
