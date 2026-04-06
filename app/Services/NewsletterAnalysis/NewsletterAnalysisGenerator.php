<?php

namespace App\Services\NewsletterAnalysis;

use App\Services\OpenRouterService;

class NewsletterAnalysisGenerator
{
    public function __construct(
        private readonly OpenRouterService $openRouter,
    ) {}

    /**
     * @return array{
     *     summary: string,
     *     key_points: list<string>,
     *     positives_mentioned: list<string>,
     *     negatives_mentioned: list<string>,
     *     highlights: list<string>,
     *     quality_notes: ?string
     * }
     */
    public function generate(string $subject, string $body): array
    {
        return $this->openRouter->analyzeNewsletter($subject, $body);
    }
}
