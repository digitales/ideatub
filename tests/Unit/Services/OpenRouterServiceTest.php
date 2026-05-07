<?php

namespace Tests\Unit\Services;

use App\Services\OpenRouterService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenRouterServiceTest extends TestCase
{
    private OpenRouterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.openrouter.api_key', 'test-api-key');
        Config::set('services.openrouter.embedding_model', 'openai/text-embedding-3-small');
        Config::set('services.openrouter.embedding_max_input_chars', 24000);
        Config::set('services.openrouter.metadata_model', 'openai/gpt-4o-mini');
        $this->service = new OpenRouterService;
    }

    #[Test]
    public function embed_returns_vector_from_openrouter_embeddings_api(): void
    {
        $vector = array_fill(0, 1536, 0.01);
        Http::fake([
            'https://openrouter.ai/api/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => $vector],
                ],
            ], 200),
        ]);

        $result = $this->service->embed('Hello world');

        $this->assertIsArray($result);
        $this->assertCount(1536, $result);
        $this->assertSame(0.01, $result[0]);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://openrouter.ai/api/v1/embeddings'
                && $request['input'] === 'Hello world'
                && $request['model'] === 'openai/text-embedding-3-small'
                && $request->hasHeader('Authorization', 'Bearer test-api-key');
        });
    }

    #[Test]
    public function embed_throws_when_api_key_is_missing(): void
    {
        Config::set('services.openrouter.api_key', null);
        $this->service = new OpenRouterService;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OPENROUTER_API_KEY is not set');

        $this->service->embed('text');
    }

    #[Test]
    public function embed_throws_on_http_error(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/embeddings' => Http::response(['error' => 'Server error'], 500),
        ]);

        $this->expectException(RequestException::class);

        $this->service->embed('text');
    }

    #[Test]
    public function embed_throws_when_response_missing_embedding(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/embeddings' => Http::response(['data' => []], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('embedding');

        $this->service->embed('text');
    }

    #[Test]
    public function embed_truncates_oversized_input_before_sending_request(): void
    {
        Config::set('services.openrouter.embedding_max_input_chars', 10);
        $vector = array_fill(0, 3, 0.5);

        Http::fake([
            'https://openrouter.ai/api/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => $vector],
                ],
            ], 200),
        ]);

        $result = $this->service->embed('0123456789EXTRA');

        $this->assertSame($vector, $result);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://openrouter.ai/api/v1/embeddings'
                && $request['input'] === '0123456789'
                && $request['model'] === 'openai/text-embedding-3-small';
        });
    }

    #[Test]
    public function embed_throws_clear_exception_when_response_contains_error_payload(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/embeddings' => Http::response([
                'error' => [
                    'message' => "HTTP 400: Invalid 'input': maximum context length is 8192 tokens.",
                    'code' => 400,
                ],
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("OpenRouter embeddings request failed: HTTP 400: Invalid 'input': maximum context length is 8192 tokens.");

        $this->service->embed(str_repeat('x', 20000));
    }

    #[Test]
    public function extract_metadata_returns_structured_array_from_chat_completion(): void
    {
        $json = '{"type":"note","tags":["work"],"people":["Alice"],"action_items":["Follow up"]}';
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

        $result = $this->service->extractMetadata('Meeting with Alice about the project.');

        $this->assertSame('note', $result['type']);
        $this->assertSame(['work'], $result['tags']);
        $this->assertSame(['Alice'], $result['people']);
        $this->assertSame(['Follow up'], $result['action_items']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && isset($request['messages'])
                && $request['model'] === 'openai/gpt-4o-mini';
        });
    }

    #[Test]
    public function extract_metadata_throws_when_api_key_is_missing(): void
    {
        Config::set('services.openrouter.api_key', null);
        $this->service = new OpenRouterService;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OPENROUTER_API_KEY is not set');

        $this->service->extractMetadata('text');
    }

    #[Test]
    public function extract_metadata_throws_on_http_error(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response(['error' => 'Server error'], 500),
        ]);

        $this->expectException(RequestException::class);

        $this->service->extractMetadata('text');
    }

    #[Test]
    public function research_note_sends_prompt_to_chat_url_and_returns_plain_text(): void
    {
        $mockedNote = 'Relevant: SaaS trends. Considerations: MVP scope. Next steps: validate idea, pick stack, ship landing.';
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => $mockedNote,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->researchNote('build a small SaaS.');

        $this->assertNotEmpty($result);
        $this->assertSame($mockedNote, $result);
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://openrouter.ai/api/v1/chat/completions') {
                return false;
            }
            $userMessage = $this->getUserMessageContent($request);

            return $userMessage !== null
                && str_contains($userMessage, 'Topic/idea to research')
                && str_contains($userMessage, 'build a small SaaS.')
                && str_contains($userMessage, 'research agent');
        });
    }

    #[Test]
    public function research_note_uses_template_file_when_available(): void
    {
        $template = 'Idea to research: {{idea}}. Prior: {{existing_research}}.';
        $tempPath = sys_get_temp_dir().'/ideatub_research_prompt_'.uniqid().'.md';
        file_put_contents($tempPath, $template);

        try {
            config(['research.prompt_path' => $tempPath]);

            Http::fake([
                'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                    'choices' => [['message' => ['content' => 'Done.']]],
                ], 200),
            ]);

            $this->service->researchNote('Build a small SaaS.', 'Some prior notes.');

            Http::assertSent(function ($request) {
                $userMessage = $this->getUserMessageContent($request);

                return $userMessage !== null
                    && str_contains($userMessage, 'Idea to research: Build a small SaaS.')
                    && str_contains($userMessage, 'Prior: Some prior notes.');
            });
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    #[Test]
    public function research_note_falls_back_to_hardcoded_prompt_when_file_missing(): void
    {
        config(['research.prompt_path' => '/nonexistent/path/research.md']);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ], 200),
        ]);

        $this->service->researchNote('My idea.');

        Http::assertSent(function ($request) {
            $userMessage = $this->getUserMessageContent($request);

            return $userMessage !== null
                && str_contains($userMessage, 'Given this idea')
                && str_contains($userMessage, 'My idea.')
                && str_contains($userMessage, 'research note');
        });
    }

    #[Test]
    public function research_note_omits_existing_research_sentence_when_empty(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ], 200),
        ]);

        $this->service->researchNote('An idea.', null);

        Http::assertSent(function ($request) {
            $userMessage = $this->getUserMessageContent($request);

            return $userMessage !== null
                && ! str_contains($userMessage, 'Existing research: .');
        });
    }

    #[Test]
    public function research_from_prompt_prefers_dedicated_research_model_when_configured(): void
    {
        Config::set('services.openrouter.research_model', 'openai/gpt-4.1-mini');

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ], 200),
        ]);

        $result = $this->service->researchFromPrompt('Prompt body');

        $this->assertSame('OK', $result);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request['model'] === 'openai/gpt-4.1-mini'
                && $this->getUserMessageContent($request) === 'Prompt body';
        });
    }

    #[Test]
    public function summarize_link_truncates_multibyte_text_without_breaking_utf8(): void
    {
        $json = json_encode([
            'title' => 'Article headline',
            'summary_text' => 'Two sentence summary of the page.',
            'support_judgment' => 'supports',
            'why_it_matters' => 'Useful for editorial review.',
            'quality_notes' => null,
            'usefulness_score' => 70,
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

        $fetchedText = 'a'.str_repeat('界', 4000);

        $this->service->summarizeLink(
            fetchedTitle: 'UTF-8 title',
            fetchedText: $fetchedText,
            sourceExcerpt: 'Newsletter context.',
        );

        Http::assertSent(function ($request) {
            $userMessage = $this->getUserMessageContent($request);

            return $userMessage !== null
                && mb_check_encoding($userMessage, 'UTF-8')
                && str_contains($userMessage, 'UTF-8 title')
                && str_contains($userMessage, 'Newsletter context.');
        });
    }

    #[Test]
    public function analyze_newsletter_returns_structured_analysis(): void
    {
        Config::set('services.openrouter.api_key', 'test-api-key');
        Config::set('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

        $json = json_encode([
            'summary' => 'A weekly roundup covering AI tooling and developer productivity.',
            'key_points' => ['OpenAI released GPT-5', 'Cursor raised Series B'],
            'positives_mentioned' => ['Bullish on AI coding assistants', 'Well-structured overview'],
            'negatives_mentioned' => ['Sceptical of AGI timelines', 'Lacks citations for key claims'],
            'highlights' => ['Subscriber count now 50k'],
            'quality_notes' => null,
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $json]]],
            ], 200),
        ]);

        $result = $this->service->analyzeNewsletter(
            subject: 'AI Weekly #42',
            body: str_repeat('Substantive newsletter body paragraph. ', 30),
        );

        $this->assertSame('A weekly roundup covering AI tooling and developer productivity.', $result['summary']);
        $this->assertSame(['OpenAI released GPT-5', 'Cursor raised Series B'], $result['key_points']);
        $this->assertSame(['Bullish on AI coding assistants', 'Well-structured overview'], $result['positives_mentioned']);
        $this->assertSame(['Sceptical of AGI timelines', 'Lacks citations for key claims'], $result['negatives_mentioned']);
        $this->assertSame(['Subscriber count now 50k'], $result['highlights']);
        $this->assertNull($result['quality_notes']);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];
            $user = collect($messages)->firstWhere('role', 'user');
            $system = collect($messages)->firstWhere('role', 'system');

            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && str_contains((string) ($user['content'] ?? ''), 'AI Weekly #42')
                && str_contains((string) ($system['content'] ?? ''), 'positives_mentioned');
        });
    }

    #[Test]
    public function analyze_newsletter_appends_truncation_note_to_quality_notes_when_body_exceeds_limit(): void
    {
        Config::set('services.openrouter.api_key', 'test-api-key');
        Config::set('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

        $json = json_encode([
            'summary' => 'Summary of truncated body.',
            'key_points' => [],
            'positives_mentioned' => [],
            'negatives_mentioned' => [],
            'highlights' => [],
            'quality_notes' => null,
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $json]]],
            ], 200),
        ]);

        // Body that exceeds the 8,000-character truncation limit
        $longBody = str_repeat('x', 9_000);

        $result = $this->service->analyzeNewsletter(subject: 'Long newsletter', body: $longBody);

        $this->assertNotNull($result['quality_notes']);
        $this->assertStringContainsString('truncated', $result['quality_notes']);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];
            $user = collect($messages)->firstWhere('role', 'user');
            $body = (string) ($user['content'] ?? '');

            // Truncated body should be shorter than the original 9,000 chars
            return mb_strlen($body) < 9_200;
        });
    }

    private function getUserMessageContent($request): ?string
    {
        if ($request->url() !== 'https://openrouter.ai/api/v1/chat/completions') {
            return null;
        }
        $messages = $request['messages'] ?? [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user') {
                return $m['content'] ?? null;
            }
        }

        return null;
    }
}
