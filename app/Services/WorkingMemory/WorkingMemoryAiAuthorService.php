<?php

namespace App\Services\WorkingMemory;

use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Composer\WorkingMemoryComposerMarkdownParser;
use App\Services\WorkingMemory\Composer\WorkingMemoryComposerPromptBuilder;
use App\Support\Json\LlmDecodeFailureLogContext;
use App\Support\Json\LlmJsonDecoder;
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
        $finishReason = null;
        $composeModel = null;
        $composeMaxTokens = null;

        try {
            $prompt = $this->promptBuilder->build($evidencePack);
            $model = (string) config('working_memory.authoring_composer_model', '');
            $temperature = config('working_memory.authoring_composer_temperature');
            $maxTokens = max(1, (int) config('working_memory.composer_max_tokens', 4096));
            $completion = $this->openRouter->researchFromPromptCompletion(
                $prompt,
                $model !== '' ? $model : null,
                is_numeric($temperature) ? (float) $temperature : null,
                $maxTokens,
            );
            $raw = $completion['content'];
            $finishReason = $completion['finish_reason'];
            $composeModel = $completion['model'];
            $composeMaxTokens = $completion['max_tokens'];
            $decoded = LlmJsonDecoder::decode($raw);

            if ($decoded === null) {
                $decoded = WorkingMemoryComposerMarkdownParser::parse($raw, self::REQUIRED_SECTION_KEYS);
                if ($decoded !== null) {
                    $decoded['references'] = $this->mergeReferences(
                        is_array($decoded['references'] ?? null) ? $decoded['references'] : [],
                        $this->referencesFromEvidencePack($evidencePack),
                    );
                    Log::info('WorkingMemoryAiAuthorService: parsed markdown compose output (JSON contract fallback).', [
                        'scope_type' => $evidencePack['scope_type'] ?? null,
                        'scope_key' => $evidencePack['scope_key'] ?? null,
                        'finish_reason' => $finishReason,
                    ]);
                } else {
                    Log::warning(
                        'WorkingMemoryAiAuthorService: model returned non-JSON output.',
                        LlmDecodeFailureLogContext::withOptionalRawPreview(
                            LlmDecodeFailureLogContext::withCompletionMetadata([
                                'scope_type' => $evidencePack['scope_type'] ?? null,
                                'scope_key' => $evidencePack['scope_key'] ?? null,
                            ], $finishReason, $composeModel, $composeMaxTokens),
                            (string) $raw,
                        ),
                    );

                    return $this->emptyOutput();
                }
            }

            if ($finishReason === 'length') {
                Log::warning(
                    'WorkingMemoryAiAuthorService: compose completed with finish_reason=length after retry budget.',
                    LlmDecodeFailureLogContext::withCompletionMetadata([
                        'scope_type' => $evidencePack['scope_type'] ?? null,
                        'scope_key' => $evidencePack['scope_key'] ?? null,
                    ], $finishReason, $composeModel, $composeMaxTokens),
                );
            }

            return $this->normalizeOutput($decoded);
        } catch (Throwable $e) {
            Log::warning(
                'WorkingMemoryAiAuthorService: authoring failed.',
                LlmDecodeFailureLogContext::withCompletionMetadata([
                    'message' => $e->getMessage(),
                    'scope_type' => $evidencePack['scope_type'] ?? null,
                    'scope_key' => $evidencePack['scope_key'] ?? null,
                ], $finishReason, $composeModel, $composeMaxTokens),
            );

            return $this->emptyOutput();
        }
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
    /**
     * @param  array<string, mixed>  $evidencePack
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function referencesFromEvidencePack(array $evidencePack): array
    {
        $rows = [];
        $seen = [];

        foreach (['signals', 'compactions'] as $key) {
            $sources = is_array($evidencePack[$key] ?? null) ? $evidencePack[$key] : [];
            foreach ($sources as $source) {
                if (! is_array($source)) {
                    continue;
                }
                foreach (is_array($source['references'] ?? null) ? $source['references'] : [] as $reference) {
                    if (! is_array($reference)) {
                        continue;
                    }
                    $url = trim((string) ($reference['url'] ?? ''));
                    $label = trim((string) ($reference['label'] ?? ''));
                    if ($url === '' || $label === '') {
                        continue;
                    }
                    $signature = $url.'|'.$label;
                    if (isset($seen[$signature])) {
                        continue;
                    }
                    $seen[$signature] = true;
                    $type = trim((string) ($reference['type'] ?? 'source'));
                    $rows[] = [
                        'type' => $type !== '' ? $type : 'source',
                        'url' => $url,
                        'label' => $label,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $parsedReferences
     * @param  array<int, array{type: string, url: string, label: string}>  $evidenceReferences
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function mergeReferences(array $parsedReferences, array $evidenceReferences): array
    {
        $merged = $this->normalizeCitations($parsedReferences);
        if ($merged !== []) {
            return $merged;
        }

        return $evidenceReferences;
    }

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
