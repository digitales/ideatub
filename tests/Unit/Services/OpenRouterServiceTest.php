<?php

namespace Tests\Unit\Services;

use App\Services\OpenRouterService;
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
}
