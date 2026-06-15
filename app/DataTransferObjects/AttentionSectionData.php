<?php

namespace App\DataTransferObjects;

/**
 * Grouped section on the Pulse page.
 */
readonly class AttentionSectionData
{
    /**
     * @param  list<AttentionItemData>  $items
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public array $items,
    ) {}
}
