<?php

namespace App\Services\LinkSummary;

use App\Services\OpenRouterService;

class LinkSummaryGenerator
{
    public function __construct(
        private OpenRouterService $openRouter,
    ) {}

    /**
     * @return array{
     *     title: string,
     *     summary_text: string,
     *     support_judgment: string,
     *     why_it_matters: string,
     *     quality_notes: ?string,
     *     usefulness_score: int
     * }
     */
    public function generate(string $fetchedTitle, string $fetchedText, string $sourceExcerpt): array
    {
        return $this->openRouter->summarizeLink($fetchedTitle, $fetchedText, $sourceExcerpt);
    }
}
