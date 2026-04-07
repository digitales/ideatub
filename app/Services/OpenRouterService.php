<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OpenRouterService
{
    private const EMBEDDINGS_URL = 'https://openrouter.ai/api/v1/embeddings';

    private const CHAT_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Get embedding vector for the given text (1536 dimensions for storage in thoughts.embedding).
     *
     * @return array<int, float> Embedding vector
     *
     * @throws RequestException On HTTP errors
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
            Log::error('OpenRouter embeddings response missing data[0].embedding.', [
                'status' => $response->status(),
                'model' => $model,
                'body_preview' => Str::limit($response->body(), 800),
            ]);
            throw new \RuntimeException('OpenRouter embeddings response missing data[0].embedding.');
        }

        return $embedding;
    }

    /**
     * Extract metadata (type, tags, people, action_items) from the given text using a small completion model.
     *
     * @return array{type?: string, tags?: array<int, string>, people?: array<int, string>, action_items?: array<int, string>}
     *
     * @throws RequestException On HTTP errors
     * @throws \RuntimeException If OPENROUTER_API_KEY is not set
     */
    public function extractMetadata(string $text): array
    {
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('OPENROUTER_API_KEY is not set.');
        }

        $model = config(
            'services.openrouter.research_model',
            config('services.openrouter.metadata_model', 'openai/gpt-4o-mini')
        );

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
     * @throws RequestException On HTTP errors
     * @throws \RuntimeException If OPENROUTER_API_KEY is not set or response missing content
     */
    public function researchNote(string $ideaContent, ?string $existingResearch = null): string
    {
        $userMessage = $this->buildResearchPrompt(trim($ideaContent), $existingResearch);

        return $this->researchFromPrompt($userMessage);
    }

    /**
     * Run a research-style completion using the full user prompt (no template merge).
     *
     * @throws RequestException On HTTP errors
     * @throws \RuntimeException If OPENROUTER_API_KEY is not set or response missing content
     */
    public function researchFromPrompt(string $userPrompt): string
    {
        $userPrompt = trim($userPrompt);
        if ($userPrompt === '') {
            throw new \RuntimeException('Research prompt is empty.');
        }

        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('OPENROUTER_API_KEY is not set.');
        }

        $model = config(
            'services.openrouter.research_model',
            config('services.openrouter.metadata_model', 'openai/gpt-4o-mini')
        );

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_tokens' => config('research.max_tokens', 2048),
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
            $template = 'Given this idea: {{idea}}. Produce a short research note: 2–4 sentences on what\'s relevant, key considerations, and 2–3 concrete next steps. Be concise.'."\n".'Existing research: {{existing_research}}. You may extend or refresh it.';
        }

        $userMessage = str_replace(
            ['{{idea}}', '{{existing_research}}'],
            [$ideaContent, $existing],
            $template
        );

        if ($existing === '') {
            // Remove the "Existing research..." line when there is no prior research (any wording)
            $userMessage = preg_replace(
                '/\n\s*Existing research[^\n]*: \s*\.?\s*\n?/',
                "\n",
                $userMessage
            );
            $userMessage = trim($userMessage);
        }

        return $userMessage;
    }

    /**
     * Summarize a fetched page for newsletter editorial context. Returns a strict JSON-shaped array.
     *
     * @return array{
     *     title: string,
     *     summary_text: string,
     *     support_judgment: string,
     *     why_it_matters: string,
     *     quality_notes: ?string,
     *     usefulness_score: int
     * }
     *
     * @throws RequestException On HTTP errors
     * @throws \RuntimeException If OPENROUTER_API_KEY is not set or JSON is invalid
     */
    public function summarizeLink(string $fetchedTitle, string $fetchedText, string $sourceExcerpt): array
    {
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('OPENROUTER_API_KEY is not set.');
        }

        $model = config('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

        $maxChars = 12_000;
        $textSnippet = mb_strlen($fetchedText) > $maxChars
            ? mb_substr($fetchedText, 0, $maxChars)
            : $fetchedText;

        $systemPrompt = <<<'PROMPT'
You help a newsletter editor understand outbound links. Reply with only a single JSON object (no markdown fences, no explanation) with these keys:
- "title" (string): a concise headline for the link
- "summary_text" (string): 2–4 sentences summarizing the page from the visible text
- "support_judgment" (string): exactly one of "supports", "adds_context", "mostly_tangential", "unclear" — how the page relates to the newsletter excerpt
- "why_it_matters" (string): one or two sentences for the editor
- "quality_notes" (string or null): caveats (e.g. thin page, paywall guess); null if none
- "usefulness_score" (integer 0–100): how useful this summary is for editorial triage
PROMPT;

        $userContent = "Page title:\n".trim($fetchedTitle)."\n\nVisible page text:\n".trim($textSnippet)."\n\nNewsletter source excerpt (context):\n".trim($sourceExcerpt);

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post(self::CHAT_URL, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
                'max_tokens' => 768,
            ]);

        $response->throw();

        $content = $response->json('choices.0.message.content');
        if ($content === null || $content === '') {
            throw new \RuntimeException('OpenRouter link summary response missing choices[0].message.content.');
        }

        $content = trim((string) $content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $content) ?? $content;
            $content = trim($content);
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('OpenRouter link summary response was not valid JSON.');
        }

        $allowed = ['supports', 'adds_context', 'mostly_tangential', 'unclear'];
        $judgment = isset($decoded['support_judgment']) && is_string($decoded['support_judgment'])
            ? $decoded['support_judgment']
            : 'unclear';
        if (! in_array($judgment, $allowed, true)) {
            $judgment = 'unclear';
        }

        $score = isset($decoded['usefulness_score']) ? (int) $decoded['usefulness_score'] : 0;
        $score = max(0, min(100, $score));

        $quality = $decoded['quality_notes'] ?? null;
        if ($quality !== null && $quality !== '') {
            $quality = is_string($quality) ? trim($quality) : null;
        } else {
            $quality = null;
        }

        return [
            'title' => is_string($decoded['title'] ?? null) ? Str::squish($decoded['title']) : '',
            'summary_text' => is_string($decoded['summary_text'] ?? null) ? trim($decoded['summary_text']) : '',
            'support_judgment' => $judgment,
            'why_it_matters' => is_string($decoded['why_it_matters'] ?? null) ? trim($decoded['why_it_matters']) : '',
            'quality_notes' => $quality,
            'usefulness_score' => $score,
        ];
    }

    /**
     * Analyse a newsletter email body and return a structured summary.
     *
     * @return array{
     *     summary: string,
     *     key_points: list<string>,
     *     positives_mentioned: list<string>,
     *     negatives_mentioned: list<string>,
     *     highlights: list<string>,
     *     quality_notes: ?string
     * }
     *
     * @throws RequestException On HTTP errors
     * @throws \RuntimeException If OPENROUTER_API_KEY is not set or JSON is invalid
     */
    public function analyzeNewsletter(string $subject, string $body): array
    {
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('OPENROUTER_API_KEY is not set.');
        }

        $model = config('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

        $maxChars = 8_000;
        $truncated = mb_strlen($body) > $maxChars;
        if ($truncated) {
            $truncatedBody = mb_substr($body, 0, $maxChars);
            $lastParagraph = mb_strrpos($truncatedBody, "\n\n");
            if ($lastParagraph !== false && $lastParagraph > (int) ($maxChars * 0.8)) {
                $truncatedBody = mb_substr($truncatedBody, 0, $lastParagraph);
            }
        } else {
            $truncatedBody = $body;
        }

        $systemPrompt = <<<'PROMPT'
You analyse newsletter emails for an editor. Reply with only a single JSON object (no markdown fences, no explanation) with these keys:
- "summary" (string): a 2–4 sentence neutral overview of what this newsletter covers, its topics, framing, and scope
- "key_points" (array of strings): the main claims, findings, or stories the author highlights — one string per point
- "positives_mentioned" (array of strings): capture both (a) things the newsletter author is positive about, bullish on, or praising (e.g. "Bullish on X", "Praises Y approach") AND (b) quality strengths of the newsletter itself (e.g. "Well-sourced claims", "Clear structure") — one string per item
- "negatives_mentioned" (array of strings): capture both (a) things the author is critical or sceptical about (e.g. "Critical of Z policy", "Sceptical of Y trend") AND (b) quality weaknesses of the newsletter itself (e.g. "Surface-level on X", "Lacks evidence for claim Y") — one string per item
- "highlights" (array of strings): anything else pertinent — notable data points, surprising assertions, calls to action, recurring themes — that does not fit the above fields; use an empty array if nothing stands out
- "quality_notes" (string or null): caveats only — thin content, truncated body, unclear authorship, marketing-heavy; null if none

Write in British English. Factual, neutral, analytical tone. No padding.
PROMPT;

        $userContent = 'Subject: '.trim($subject)."\n\nNewsletter body:\n".trim($truncatedBody);

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post(self::CHAT_URL, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
                'max_tokens' => 1024,
            ]);

        $response->throw();

        $content = $response->json('choices.0.message.content');
        if ($content === null || $content === '') {
            throw new \RuntimeException('OpenRouter newsletter analysis response missing choices[0].message.content.');
        }

        $content = trim((string) $content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $content) ?? $content;
            $content = trim($content);
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('OpenRouter newsletter analysis response was not valid JSON.');
        }

        $quality = $decoded['quality_notes'] ?? null;
        $quality = ($quality !== null && $quality !== '') ? (is_string($quality) ? trim($quality) : null) : null;

        if ($truncated) {
            $truncationNote = 'Newsletter body was truncated before analysis; some content may be missing.';
            $quality = $quality !== null ? $truncationNote.' '.$quality : $truncationNote;
        }

        $toStringArray = static function (mixed $value): array {
            if (! is_array($value)) {
                return [];
            }

            return array_values(array_filter(array_map('strval', $value)));
        };

        return [
            'summary' => is_string($decoded['summary'] ?? null) ? trim($decoded['summary']) : '',
            'key_points' => $toStringArray($decoded['key_points'] ?? null),
            'positives_mentioned' => $toStringArray($decoded['positives_mentioned'] ?? null),
            'negatives_mentioned' => $toStringArray($decoded['negatives_mentioned'] ?? null),
            'highlights' => $toStringArray($decoded['highlights'] ?? null),
            'quality_notes' => $quality,
        ];
    }
}
