<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    /**
     * Produce a short research note for the given idea (plain text).
     *
     * @throws \Illuminate\Http\Client\RequestException On HTTP errors
     * @throws \RuntimeException If OPENROUTER_API_KEY is not set or response missing content
     */
    public function researchNote(string $ideaContent, ?string $existingResearch = null): string
    {
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('OPENROUTER_API_KEY is not set.');
        }

        $model = config('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

        $userMessage = $this->buildResearchPrompt(trim($ideaContent), $existingResearch);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => 512,
        ];

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post(self::CHAT_URL, $payload);

        $response->throw();

        $content = $response->json('choices.0.message.content');
        if ($content === null || $content === '') {
            throw new \RuntimeException('OpenRouter research note response missing choices[0].message.content.');
        }

        return (string) $content;
    }

    /**
     * Build the user message for research: load template from file or fall back to hardcoded prompt.
     */
    private function buildResearchPrompt(string $ideaContent, ?string $existingResearch): string
    {
        $path = config('research.prompt_path');
        $existing = ($existingResearch !== null && $existingResearch !== '') ? trim($existingResearch) : '';

        if ($path !== null && $path !== '' && is_readable($path)) {
            $template = trim((string) file_get_contents($path));
        } else {
            Log::warning('Research prompt file not used.', ['path' => $path ?? 'empty']);
            $template = 'Given this idea: {{idea}}. Produce a short research note: 2–4 sentences on what\'s relevant, key considerations, and 2–3 concrete next steps. Be concise.' . "\n" . 'Existing research: {{existing_research}}. You may extend or refresh it.';
        }

        $userMessage = str_replace(
            ['{{idea}}', '{{existing_research}}'],
            [$ideaContent, $existing],
            $template
        );

        if ($existing === '') {
            $userMessage = preg_replace(
                '/\s*Existing research: \.?\s*You may extend or refresh it\.?\s*/',
                ' ',
                $userMessage
            );
            $userMessage = trim(preg_replace('/\s+/', ' ', $userMessage));
        }

        return $userMessage;
    }
}
