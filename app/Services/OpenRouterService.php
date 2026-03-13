<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenRouterService
{
    private const EMBEDDINGS_URL = 'https://openrouter.ai/api/v1/embeddings';

    private const CHAT_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Get embedding vector for the given text (1536 dimensions for storage in thoughts.embedding).
     *
     * @return array<int, float> Embedding vector
     *
     * @throws \Illuminate\Http\Client\RequestException On HTTP errors
     * @throws \RuntimeException If OPENROUTER_API_KEY is not set
     */
    public function embed(string $text): array
    {
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('OPENROUTER_API_KEY is not set.');
        }

        $model = config('services.openrouter.embedding_model', 'openai/text-embedding-3-small');

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post(self::EMBEDDINGS_URL, [
                'input' => $text,
                'model' => $model,
            ]);

        $response->throw();

        $embedding = $response->json('data.0.embedding');
        if (! is_array($embedding)) {
            throw new \RuntimeException('OpenRouter embeddings response missing data[0].embedding.');
        }

        return $embedding;
    }

    /**
     * Extract metadata (type, tags, people, action_items) from the given text using a small completion model.
     *
     * @return array{type?: string, tags?: array<int, string>, people?: array<int, string>, action_items?: array<int, string>}
     *
     * @throws \Illuminate\Http\Client\RequestException On HTTP errors
     * @throws \RuntimeException If OPENROUTER_API_KEY is not set
     */
    public function extractMetadata(string $text): array
    {
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('OPENROUTER_API_KEY is not set.');
        }

        $model = config('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

        $systemPrompt = 'You extract metadata from a thought or note. Reply with only a single JSON object (no markdown, no explanation) with these keys: "type" (string: e.g. idea, note, task, meeting, quote), "tags" (array of strings: include topics, project names, client or organization names, product names, and other meaningful labels for finding this note later; e.g. "mastercard foundation" for a note about Mastercard Foundation), "people" (array of strings), "action_items" (array of strings). Use empty arrays or omit keys if none apply.';

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post(self::CHAT_URL, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $text],
                ],
                'max_tokens' => 512,
            ]);

        $response->throw();

        $content = $response->json('choices.0.message.content');
        if ($content === null || $content === '') {
            return [];
        }

        $content = trim($content);
        // Strip optional markdown code block
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $content);
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return [];
        }

        return [
            'type' => $decoded['type'] ?? null,
            'tags' => isset($decoded['tags']) && is_array($decoded['tags']) ? array_values($decoded['tags']) : [],
            'people' => isset($decoded['people']) && is_array($decoded['people']) ? array_values($decoded['people']) : [],
            'action_items' => isset($decoded['action_items']) && is_array($decoded['action_items']) ? array_values($decoded['action_items']) : [],
        ];
    }
}
