<?php

namespace Tests\Unit\Services\NewsletterAnalysis;

use App\Services\NewsletterAnalysis\NewsletterAnalysisGenerator;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NewsletterAnalysisGeneratorTest extends TestCase
{
    #[Test]
    public function generate_delegates_to_openrouter_analyze_newsletter(): void
    {
        Config::set('services.openrouter.api_key', 'test-key');
        Config::set('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

        $json = json_encode([
            'summary' => 'The newsletter covers fintech regulation.',
            'key_points' => ['FCA proposes new rules'],
            'positives_mentioned' => ['Clear writing', 'Praises open banking'],
            'negatives_mentioned' => ['Critical of big banks'],
            'highlights' => ['200k subscribers milestone'],
            'quality_notes' => null,
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $json]]],
            ], 200),
        ]);

        $generator = new NewsletterAnalysisGenerator(new OpenRouterService);

        $result = $generator->generate(
            subject: 'Fintech Weekly #10',
            body: str_repeat('Fintech newsletter body. ', 20),
        );

        $this->assertSame('The newsletter covers fintech regulation.', $result['summary']);
        $this->assertSame(['FCA proposes new rules'], $result['key_points']);
        $this->assertSame(['Clear writing', 'Praises open banking'], $result['positives_mentioned']);
        $this->assertSame(['Critical of big banks'], $result['negatives_mentioned']);
        $this->assertSame(['200k subscribers milestone'], $result['highlights']);
        $this->assertNull($result['quality_notes']);
    }
}
