<?php

namespace App\DataTransferObjects;

/**
 * Greeting and optional "continue" cards for the logged-in capture home.
 */
readonly class MorningBriefData
{
    /**
     * @param  list<MorningBriefCardData>  $cards
     */
    public function __construct(
        public string $greeting,
        public array $cards,
    ) {}

    public function hasCards(): bool
    {
        return $this->cards !== [];
    }
}
