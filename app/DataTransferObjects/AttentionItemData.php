<?php

namespace App\DataTransferObjects;

/**
 * One row on the Pulse attention overview.
 */
readonly class AttentionItemData
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array{type: string, id: string}|null  $sourceRef
     */
    public function __construct(
        public string $kind,
        public ?string $severity,
        public string $title,
        public ?string $subtitle,
        public string $href,
        public array $meta = [],
        public ?array $sourceRef = null,
        public ?string $commitmentId = null,
    ) {}
}
