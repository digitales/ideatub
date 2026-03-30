<?php

namespace Tests\Unit\Services\LinkSummary;

use App\Services\LinkSummary\LinkSummaryGenerator;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LinkSummaryGeneratorTest extends TestCase
{
    #[Test]
    public function generate_returns_structured_summary_from_openrouter_summarize_link(): void
    {
        Config::set('services.openrouter.api_key', 'test-api-key');
        Config::set('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

        $json = json_encode([
            'title' => 'Article headline',
            'summary_text' => 'Two sentence summary of the page.',
            'support_judgment' => 'adds_context',
            'why_it_matters' => 'Connects to the newsletter theme.',
            'quality_notes' => null,
            'usefulness_score' => 72,
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => $json,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $openRouter = new OpenRouterService;
        $generator = new LinkSummaryGenerator($openRouter);

        $result = $generator->generate(
            fetchedTitle: 'Original HTML title',
            fetchedText: str_repeat('Substantive page body paragraph. ', 20),
            sourceExcerpt: 'Newsletter blurb around the link.'
        );

        $this->assertSame('Article headline', $result['title']);
        $this->assertSame('Two sentence summary of the page.', $result['summary_text']);
        $this->assertSame('adds_context', $result['support_judgment']);
        $this->assertSame('Connects to the newsletter theme.', $result['why_it_matters']);
        $this->assertNull($result['quality_notes']);
        $this->assertSame(72, $result['usefulness_score']);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $user = collect($body['messages'] ?? [])->firstWhere('role', 'user');

            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && str_contains((string) ($user['content'] ?? ''), 'Original HTML title')
                && str_contains((string) ($user['content'] ?? ''), 'Newsletter blurb around the link.');
        });
    }
}
