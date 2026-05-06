<?php

namespace App\Services\WorkingMemory;

use Illuminate\Support\Str;

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

    /**
     * Deterministic authoring placeholder until model-backed composition is enabled.
     *
     * @return array{
     *     summary_markdown: string,
     *     structured_sections: array{
     *         Current Focus: array<int, string>,
     *         Active Priorities: array<int, string>,
     *         Recent Changes: array<int, string>,
     *         Open Questions: array<int, string>,
     *         Risks / Blockers: array<int, string>,
     *         Next Actions: array<int, string>,
     *         Latest Signals: array<int, string>,
     *         Source Notes: array<int, string>
     *     },
     *     references: array<int, array{type: string, url: string, label: string}>
     * }
     */
    public function authorFromEvidence(array $evidencePack): array
    {
        $signals = collect($evidencePack['signals'] ?? [])
            ->filter(fn ($signal): bool => is_array($signal))
            ->values();

        $references = $this->collectReferences($signals->all());
        $citation = fn (int $referenceIndex): string => $referenceIndex > 0 ? " [{$referenceIndex}]" : '';

        $currentSignal = $signals->first();
        $currentContent = $this->signalContent($currentSignal);
        $firstReferenceIndex = $this->firstReferenceIndexForSignal($currentSignal, $references);

        $priorities = $signals
            ->take(3)
            ->map(function (array $signal) use ($citation, $references): string {
                $content = $this->signalContent($signal);
                $referenceIndex = $this->firstReferenceIndexForSignal($signal, $references);

                return 'Advance: '.Str::limit($content, 160).$citation($referenceIndex);
            })
            ->values()
            ->all();

        $changes = $signals
            ->take(3)
            ->map(function (array $signal) use ($citation, $references): string {
                $content = $this->signalContent($signal);
                $referenceIndex = $this->firstReferenceIndexForSignal($signal, $references);

                return 'Observed: '.Str::limit($content, 160).$citation($referenceIndex);
            })
            ->values()
            ->all();

        $questions = $signals
            ->filter(fn (array $signal): bool => str_contains($this->signalContent($signal), '?'))
            ->take(3)
            ->map(function (array $signal) use ($citation, $references): string {
                $content = $this->signalContent($signal);
                $referenceIndex = $this->firstReferenceIndexForSignal($signal, $references);

                return Str::finish(Str::limit($content, 160, ''), '?').$citation($referenceIndex);
            })
            ->values()
            ->all();

        $risks = $signals
            ->filter(function (array $signal): bool {
                $content = Str::lower($this->signalContent($signal));

                return str_contains($content, 'risk')
                    || str_contains($content, 'block')
                    || str_contains($content, 'issue')
                    || str_contains($content, 'delay');
            })
            ->take(3)
            ->map(function (array $signal) use ($citation, $references): string {
                $content = $this->signalContent($signal);
                $referenceIndex = $this->firstReferenceIndexForSignal($signal, $references);

                return Str::limit($content, 160).$citation($referenceIndex);
            })
            ->values()
            ->all();

        $nextActions = $signals
            ->take(3)
            ->map(function (array $signal) use ($citation, $references): string {
                $content = $this->signalContent($signal);
                $referenceIndex = $this->firstReferenceIndexForSignal($signal, $references);

                return 'Review and act on: '.Str::limit($content, 140).$citation($referenceIndex);
            })
            ->values()
            ->all();

        $latestSignals = $signals
            ->take(3)
            ->map(function (array $signal) use ($citation, $references): string {
                $createdAt = trim((string) ($signal['created_at'] ?? ''));
                $content = Str::limit($this->signalContent($signal), 120);
                $referenceIndex = $this->firstReferenceIndexForSignal($signal, $references);

                return ($createdAt !== '' ? "{$createdAt} - " : '').$content.$citation($referenceIndex);
            })
            ->values()
            ->all();

        $sourceNotes = collect($references)
            ->values()
            ->map(fn (array $reference, int $index): string => sprintf(
                '[%d] %s - %s',
                $index + 1,
                $reference['label'],
                $reference['url']
            ))
            ->all();

        $structuredSections = [
            'Current Focus' => [
                ($currentContent !== '' ? Str::limit($currentContent, 180) : 'No signals yet for this scope.').$citation($firstReferenceIndex),
            ],
            'Active Priorities' => $this->nonEmptyOrFallback($priorities, ['Capture additional high-signal updates.']),
            'Recent Changes' => $this->nonEmptyOrFallback($changes, ['No significant changes captured in this window.']),
            'Open Questions' => $this->nonEmptyOrFallback($questions, ['What additional evidence is needed to reduce uncertainty?']),
            'Risks / Blockers' => $this->nonEmptyOrFallback($risks, ['No explicit blockers detected in the current signals.']),
            'Next Actions' => $this->nonEmptyOrFallback($nextActions, ['Capture more context before the next refresh cycle.']),
            'Latest Signals' => $this->nonEmptyOrFallback($latestSignals, ['No timestamped signals available.']),
            'Source Notes' => $this->nonEmptyOrFallback($sourceNotes, ['No source references available yet.']),
        ];

        $summaryMarkdown = $this->renderSummaryMarkdown($structuredSections);

        return [
            'summary_markdown' => $summaryMarkdown,
            'structured_sections' => $structuredSections,
            'references' => $references,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function collectReferences(array $signals): array
    {
        $references = [];
        $seen = [];

        foreach ($signals as $signal) {
            $signalReferences = $signal['references'] ?? [];
            if (! is_array($signalReferences)) {
                continue;
            }

            foreach ($signalReferences as $reference) {
                if (! is_array($reference)) {
                    continue;
                }

                $url = trim((string) ($reference['url'] ?? ''));
                $label = trim((string) ($reference['label'] ?? ''));
                $type = trim((string) ($reference['type'] ?? 'source'));

                if ($url === '' || $label === '') {
                    continue;
                }

                $identity = strtolower($url.'|'.$label);
                if (isset($seen[$identity])) {
                    continue;
                }

                $seen[$identity] = true;
                $references[] = [
                    'type' => $type !== '' ? $type : 'source',
                    'url' => $url,
                    'label' => $label,
                ];
            }
        }

        return $references;
    }

    /**
     * @param  array<string, mixed>|null  $signal
     * @param  array<int, array{type: string, url: string, label: string}>  $allReferences
     */
    private function firstReferenceIndexForSignal(?array $signal, array $allReferences): int
    {
        if ($signal === null) {
            return 0;
        }

        $signalReferences = $signal['references'] ?? [];
        if (! is_array($signalReferences) || $signalReferences === []) {
            return 0;
        }

        foreach ($signalReferences as $reference) {
            if (! is_array($reference)) {
                continue;
            }

            $url = trim((string) ($reference['url'] ?? ''));
            $label = trim((string) ($reference['label'] ?? ''));
            if ($url === '' || $label === '') {
                continue;
            }

            foreach ($allReferences as $index => $candidate) {
                if ($candidate['url'] === $url && $candidate['label'] === $label) {
                    return $index + 1;
                }
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>|null  $signal
     */
    private function signalContent(?array $signal): string
    {
        if ($signal === null) {
            return '';
        }

        return trim((string) ($signal['content'] ?? ''));
    }

    /**
     * @param  array<int, string>  $items
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function nonEmptyOrFallback(array $items, array $fallback): array
    {
        $clean = collect($items)
            ->filter(fn ($item): bool => is_string($item) && trim($item) !== '')
            ->values()
            ->all();

        return $clean !== [] ? $clean : $fallback;
    }

    /**
     * @param  array<string, array<int, string>>  $structuredSections
     */
    private function renderSummaryMarkdown(array $structuredSections): string
    {
        $parts = ['# Working memory synthesis'];

        foreach (self::REQUIRED_SECTION_KEYS as $section) {
            $bullets = $structuredSections[$section] ?? ['No content captured.'];
            $lines = collect($bullets)
                ->map(fn (string $bullet): string => '- '.trim($bullet))
                ->all();

            $parts[] = '## '.$section;
            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }
}
