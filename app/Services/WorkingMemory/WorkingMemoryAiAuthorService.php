<?php

namespace App\Services\WorkingMemory;

use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Composer\WorkingMemoryComposerPromptBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class WorkingMemoryAiAuthorService
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED_SECTION_KEYS = [
        'Current Focus',
        'Active Priorities',
        'Recent Changes',
        'Open Questions',
        'Risks / Blockers',
        'Next Actions',
        'Latest Signals',
        'Source Notes',
    ];

    public function __construct(
        private WorkingMemoryComposerPromptBuilder $promptBuilder,
        private OpenRouterService $openRouter,
    ) {}

    /**
     * @param  array<string, mixed>  $evidencePack
     * @return array{
     *     summary_markdown: string,
     *     structured_sections: array<string, array<int, array{
     *         id: string,
     *         text: string,
     *         importance: int,
     *         fallback_mode: 'direct'|'section_bundle',
     *         citations: array<int, array{type: string, url: string, label: string}>
     *     }>>,
     *     references: array<int, array{type: string, url: string, label: string}>
     * }
     */
    public function authorFromEvidence(array $evidencePack): array
    {
        try {
            $prompt = $this->promptBuilder->build($evidencePack);
            $model = (string) config('working_memory.authoring_composer_model', '');
            $temperature = config('working_memory.authoring_composer_temperature');
            $raw = $this->openRouter->researchFromPrompt(
                $prompt,
                $model !== '' ? $model : null,
                is_numeric($temperature) ? (float) $temperature : null,
            );
            $decoded = $this->decodeJson($raw);

            if ($decoded === null) {
                Log::warning('WorkingMemoryAiAuthorService: model returned non-JSON output.', [
                    'scope_type' => $evidencePack['scope_type'] ?? null,
                    'scope_key' => $evidencePack['scope_key'] ?? null,
                    'preview' => Str::limit((string) $raw, 400),
                ]);

                return $this->emptyOutput();
            }

            return $this->normalizeOutput($decoded);
        } catch (Throwable $e) {
            Log::warning('WorkingMemoryAiAuthorService: authoring failed.', [
                'message' => $e->getMessage(),
            ]);

            return $this->emptyOutput();
        }
    }

    private function decodeJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/```\s*$/', '', $trimmed) ?? $trimmed;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{
     *     summary_markdown: string,
     *     structured_sections: array<string, array<int, array{
     *         id: string, text: string, importance: int,
     *         fallback_mode: 'direct'|'section_bundle',
     *         citations: array<int, array{type: string, url: string, label: string}>
     *     }>>,
     *     references: array<int, array{type: string, url: string, label: string}>
     * }
     */
    private function normalizeOutput(array $decoded): array
    {
        $summaryMarkdown = is_string($decoded['summary_markdown'] ?? null)
            ? trim($decoded['summary_markdown'])
            : '';

        $rawSections = is_array($decoded['structured_sections'] ?? null)
            ? $decoded['structured_sections']
            : [];

        $structured = [];
        foreach (self::REQUIRED_SECTION_KEYS as $section) {
            $items = [];
            $rawItems = is_array($rawSections[$section] ?? null) ? $rawSections[$section] : [];
            foreach ($rawItems as $rawItem) {
                if (! is_array($rawItem)) {
                    continue;
                }
                $text = trim((string) ($rawItem['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $citations = $this->normalizeCitations($rawItem['citations'] ?? []);
                $items[] = [
                    'id' => (string) Str::uuid(),
                    'text' => $text,
                    'importance' => (int) ($rawItem['importance'] ?? 1),
                    'fallback_mode' => $this->normalizeFallbackMode($rawItem['fallback_mode'] ?? 'direct'),
                    'citations' => $citations,
                ];
            }
            $structured[$section] = $items;
        }

        $references = $this->normalizeCitations($decoded['references'] ?? []);

        return [
            'summary_markdown' => $summaryMarkdown,
            'structured_sections' => $structured,
            'references' => $references,
        ];
    }

    /**
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function normalizeCitations(mixed $citations): array
    {
        if (! is_array($citations)) {
            return [];
        }

        $rows = [];
        foreach ($citations as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            $type = trim((string) ($row['type'] ?? 'source'));
            if ($url === '' || $label === '') {
                continue;
            }
            $rows[] = [
                'type' => $type !== '' ? $type : 'source',
                'url' => $url,
                'label' => $label,
            ];
        }

        return $rows;
    }

    /**
     * @return 'direct'|'section_bundle'
     */
    private function normalizeFallbackMode(mixed $value): string
    {
        return $value === 'section_bundle' ? 'section_bundle' : 'direct';
    }

    /**
     * @return array{summary_markdown: string, structured_sections: array<string, array<int, array<string, mixed>>>, references: array<int, mixed>}
     */
    private function emptyOutput(): array
    {
        $sections = [];
        foreach (self::REQUIRED_SECTION_KEYS as $section) {
            $sections[$section] = [];
        }

        return [
            'summary_markdown' => '',
            'structured_sections' => $sections,
            'references' => [],
        ];
    }
}
