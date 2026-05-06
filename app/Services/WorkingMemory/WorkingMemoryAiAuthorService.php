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
        $signals = collect($evidencePack['signals'] ?? [])
            ->filter(fn ($signal): bool => is_array($signal))
            ->values();

        $references = $this->collectReferences($signals->all());
        $directByNormalizedContent = $this->buildDirectCitationMap($signals->all());

        $sectionCandidates = is_array($evidencePack['section_candidates'] ?? null)
            ? $evidencePack['section_candidates']
            : [];
        $sectionBundles = is_array($evidencePack['section_bundles'] ?? null)
            ? $evidencePack['section_bundles']
            : [];

        $signalsList = $signals->all();
        $structuredSections = [];

        foreach (self::REQUIRED_SECTION_KEYS as $sectionKey) {
            if ($sectionKey === 'Source Notes') {
                $structuredSections[$sectionKey] = $this->buildSourceNotesItems($references, $sectionBundles);

                continue;
            }

            $candidates = $sectionCandidates[$sectionKey] ?? [];
            if (! is_array($candidates)) {
                $candidates = [];
            }

            $bundle = $sectionBundles[$sectionKey] ?? [];
            $bundleCitations = $this->normalizeBundleReferences(is_array($bundle) ? $bundle : []);

            $items = [];
            foreach ($candidates as $candidate) {
                if (! is_string($candidate)) {
                    continue;
                }

                $text = trim($candidate);
                if ($text === '') {
                    continue;
                }

                $normalized = $this->normalizeContentKey($text);
                $directCitations = $directByNormalizedContent[$normalized] ?? null;

                if ($directCitations !== null && $directCitations !== []) {
                    $items[] = $this->makeSectionItem(
                        text: $text,
                        fallbackMode: 'direct',
                        citations: $directCitations,
                        references: $references,
                    );

                    continue;
                }

                if ($bundleCitations !== []) {
                    $items[] = $this->makeSectionItem(
                        text: $text,
                        fallbackMode: 'section_bundle',
                        citations: $bundleCitations,
                        references: $references,
                    );
                }
            }

            if ($items === []) {
                $items[] = $this->synthesizeOperationalFallbackItem(
                    $sectionKey,
                    $signalsList,
                    $bundleCitations,
                    $sectionBundles,
                    $references,
                );
            }

            $structuredSections[$sectionKey] = $items;
        }

        $summaryMarkdown = $this->renderSummaryMarkdown($structuredSections);

        return [
            'summary_markdown' => $summaryMarkdown,
            'structured_sections' => $structuredSections,
            'references' => $references,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<string, array<int, array{type: string, url: string, label: string}>>
     */
    private function buildDirectCitationMap(array $signals): array
    {
        $best = [];

        foreach ($signals as $signal) {
            $contentKey = $this->normalizeContentKey($this->signalContent($signal));
            if ($contentKey === '') {
                continue;
            }

            $citations = $this->normalizeCitationsFromReferences($signal['references'] ?? []);
            if ($citations === []) {
                continue;
            }

            $rank = $this->citationSetRank($citations);
            $count = count($citations);

            if (! isset($best[$contentKey])) {
                $best[$contentKey] = [
                    'rank' => $rank,
                    'count' => $count,
                    'citations' => $citations,
                ];

                continue;
            }

            $existing = $best[$contentKey];
            if ($rank > $existing['rank']) {
                $best[$contentKey] = [
                    'rank' => $rank,
                    'count' => $count,
                    'citations' => $citations,
                ];

                continue;
            }

            if ($rank === $existing['rank'] && $count > $existing['count']) {
                $best[$contentKey] = [
                    'rank' => $rank,
                    'count' => $count,
                    'citations' => $citations,
                ];
            }
        }

        return collect($best)
            ->map(fn (array $entry): array => $entry['citations'])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $references
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function normalizeCitationsFromReferences(array $references): array
    {
        if (! is_array($references)) {
            return [];
        }

        $rows = [];
        foreach ($references as $reference) {
            if (! is_array($reference)) {
                continue;
            }

            $row = $this->normalizeReferenceRow($reference);
            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        return $this->orderThoughtThenSource($rows);
    }

    /**
     * @param  array<int, mixed>  $bundle
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function normalizeBundleReferences(array $bundle): array
    {
        $rows = [];
        foreach ($bundle as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $row = $this->normalizeReferenceRow($entry);
            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $reference
     * @return array{type: string, url: string, label: string}|null
     */
    private function normalizeReferenceRow(array $reference): ?array
    {
        $url = trim((string) ($reference['url'] ?? ''));
        $label = trim((string) ($reference['label'] ?? ''));
        $type = trim((string) ($reference['type'] ?? 'source'));

        if ($url === '' || $label === '') {
            return null;
        }

        return [
            'type' => $type !== '' ? $type : 'source',
            'url' => $url,
            'label' => $label,
        ];
    }

    /**
     * @param  array<int, array{type: string, url: string, label: string}>  $rows
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function orderThoughtThenSource(array $rows): array
    {
        $thoughts = [];
        $sources = [];
        $other = [];

        foreach ($rows as $row) {
            if ($row['type'] === 'thought') {
                $thoughts[] = $row;
            } elseif ($row['type'] === 'source') {
                $sources[] = $row;
            } else {
                $other[] = $row;
            }
        }

        return array_merge($thoughts, $sources, $other);
    }

    /**
     * @param  array<int, array{type: string, url: string, label: string}>  $citations
     */
    private function citationSetRank(array $citations): int
    {
        $hasThought = false;
        $hasSource = false;

        foreach ($citations as $citation) {
            if ($citation['type'] === 'thought') {
                $hasThought = true;
            }
            if ($citation['type'] === 'source') {
                $hasSource = true;
            }
        }

        if ($hasThought && $hasSource) {
            return 3;
        }

        if ($hasThought) {
            return 2;
        }

        if ($hasSource) {
            return 1;
        }

        return 0;
    }

    private function normalizeContentKey(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($text));

        return $collapsed !== null ? $collapsed : trim($text);
    }

    /**
     * @param  array<int, array{type: string, url: string, label: string}>  $citations
     * @param  array<int, array{type: string, url: string, label: string}>  $references
     * @return array{id: string, text: string, importance: int, fallback_mode: 'direct'|'section_bundle', citations: array<int, array{type: string, url: string, label: string}>}
     */
    private function makeSectionItem(string $text, string $fallbackMode, array $citations, array $references): array
    {
        return [
            'id' => (string) Str::uuid(),
            'text' => $this->finalizeItemDisplayText($text, $references),
            'importance' => 1,
            'fallback_mode' => $fallbackMode,
            'citations' => array_values($citations),
        ];
    }

    /**
     * Strips numeric bracket markers when they resolve against the reference list; preserves text
     * unchanged when markers are out of range so downstream validation can hard-fail.
     *
     * @param  array<int, array{type: string, url: string, label: string}>  $references
     */
    private function finalizeItemDisplayText(string $text, array $references): string
    {
        if ($this->bracketMarkersResolvableAgainstReferences($text, $references)) {
            $stripped = preg_replace('/\[\d+\]/u', '', $text);
            $collapsed = preg_replace('/\s+/u', ' ', trim((string) $stripped));

            return $collapsed !== '' ? $collapsed : trim($text);
        }

        return $text;
    }

    /**
     * @param  array<int, array{type: string, url: string, label: string}>  $references
     */
    private function bracketMarkersResolvableAgainstReferences(string $text, array $references): bool
    {
        if ($text === '' || ! preg_match_all('/\[(\d+)\]/', $text, $matches)) {
            return true;
        }

        if ($references === []) {
            return false;
        }

        foreach ($matches[1] as $rawIndex) {
            $index = (int) $rawIndex - 1;
            if ($index < 0 || $index >= count($references)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{type: string, url: string, label: string}>  $references
     * @param  array<string, mixed>  $sectionBundles
     * @return array<int, array{id: string, text: string, importance: int, fallback_mode: 'direct'|'section_bundle', citations: array<int, array{type: string, url: string, label: string}>}>
     */
    private function buildSourceNotesItems(array $references, array $sectionBundles): array
    {
        $items = [];

        foreach ($references as $index => $reference) {
            $line = sprintf('%s - %s', $reference['label'], $reference['url']);

            $items[] = $this->makeSectionItem(
                text: $line,
                fallbackMode: 'direct',
                citations: [
                    [
                        'type' => $reference['type'],
                        'url' => $reference['url'],
                        'label' => $reference['label'],
                    ],
                ],
                references: $references,
            );
        }

        if ($items === []) {
            $bundleCitations = $this->collectFirstAvailableBundleCitations($sectionBundles);
            if ($bundleCitations !== []) {
                $items[] = $this->makeSectionItem(
                    text: 'No source references captured yet.',
                    fallbackMode: 'section_bundle',
                    citations: $bundleCitations,
                    references: $references,
                );
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $sectionBundles
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function collectFirstAvailableBundleCitations(array $sectionBundles): array
    {
        foreach ($sectionBundles as $bundle) {
            if (! is_array($bundle)) {
                continue;
            }

            $normalized = $this->normalizeBundleReferences($bundle);
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<string, mixed>  $allSectionBundles
     * @return array{id: string, text: string, importance: int, fallback_mode: 'direct'|'section_bundle', citations: array<int, array{type: string, url: string, label: string}>}
     */
    private function synthesizeOperationalFallbackItem(
        string $sectionKey,
        array $signals,
        array $sectionBundleCitations,
        array $allSectionBundles,
        array $references,
    ): array {
        $signal = $this->selectStrongestSignal($signals);
        $directFromStrongest = $signal !== null
            ? $this->normalizeCitationsFromReferences($signal['references'] ?? [])
            : [];
        $aggregatedDirect = $this->aggregateAllSignalCitations($signals);
        $directCitations = $directFromStrongest !== [] ? $directFromStrongest : $aggregatedDirect;

        $text = $this->formatOperationalFallbackText($sectionKey, $signal);

        if ($directCitations !== []) {
            return $this->makeSectionItem(
                text: $text,
                fallbackMode: 'direct',
                citations: $directCitations,
                references: $references,
            );
        }

        if ($sectionBundleCitations !== []) {
            return $this->makeSectionItem(
                text: $text,
                fallbackMode: 'section_bundle',
                citations: $sectionBundleCitations,
                references: $references,
            );
        }

        $fromAnyBundle = $this->collectFirstAvailableBundleCitations($allSectionBundles);

        return $this->makeSectionItem(
            text: $text,
            fallbackMode: 'section_bundle',
            citations: $fromAnyBundle,
            references: $references,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function aggregateAllSignalCitations(array $signals): array
    {
        $seen = [];
        $rows = [];

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }

            foreach ($this->normalizeCitationsFromReferences($signal['references'] ?? []) as $row) {
                $key = strtolower($row['url'].'|'.$row['label']);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $rows[] = $row;
            }
        }

        return $this->orderThoughtThenSource($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<string, mixed>|null
     */
    private function selectStrongestSignal(array $signals): ?array
    {
        $best = null;
        $bestRank = -1;

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $citations = $this->normalizeCitationsFromReferences($signal['references'] ?? []);
            $rank = $this->citationSetRank($citations);
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $signal;
            }
        }

        if ($best !== null && $bestRank > 0) {
            return $best;
        }

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }

            if ($this->signalContent($signal) !== '') {
                return $signal;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $signal
     */
    private function formatOperationalFallbackText(string $sectionKey, ?array $signal): string
    {
        $content = $signal !== null ? $this->signalContent($signal) : '';
        $lower = Str::lower($content);

        return match ($sectionKey) {
            'Current Focus' => $content !== ''
                ? Str::limit($content, 180)
                : 'No signals yet for this scope.',
            'Active Priorities' => $content !== ''
                ? 'Advance: '.Str::limit($content, 160)
                : 'Capture additional high-signal updates.',
            'Recent Changes' => $content !== ''
                ? 'Observed: '.Str::limit($content, 160)
                : 'No significant changes captured in this window.',
            'Open Questions' => str_contains($content, '?')
                ? Str::finish(Str::limit($content, 160, ''), '?')
                : 'What additional evidence is needed to reduce uncertainty?',
            'Risks / Blockers' => (
                str_contains($lower, 'risk')
                || str_contains($lower, 'block')
                || str_contains($lower, 'issue')
                || str_contains($lower, 'delay')
            ) && $content !== ''
                ? Str::limit($content, 160)
                : 'No explicit blockers detected in the current signals.',
            'Next Actions' => $content !== ''
                ? 'Review and act on: '.Str::limit($content, 140)
                : 'Capture more context before the next refresh cycle.',
            'Latest Signals' => $this->formatLatestSignalsFallbackText($signal),
            default => Str::limit($content !== '' ? $content : 'No content captured.', 180),
        };
    }

    /**
     * @param  array<string, mixed>|null  $signal
     */
    private function formatLatestSignalsFallbackText(?array $signal): string
    {
        if ($signal === null) {
            return 'No timestamped signals available.';
        }

        $createdAt = trim((string) ($signal['created_at'] ?? ''));
        $content = Str::limit($this->signalContent($signal), 120);

        if ($content === '') {
            return 'No timestamped signals available.';
        }

        return ($createdAt !== '' ? "{$createdAt} - " : '').$content;
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
     */
    private function signalContent(?array $signal): string
    {
        if ($signal === null) {
            return '';
        }

        return trim((string) ($signal['content'] ?? ''));
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $structuredSections
     */
    private function renderSummaryMarkdown(array $structuredSections): string
    {
        $parts = ['# Working memory synthesis'];

        foreach (self::REQUIRED_SECTION_KEYS as $section) {
            $items = $structuredSections[$section] ?? [];
            if ($items === []) {
                $lines = ['- No content captured.'];
            } else {
                $lines = [];
                foreach ($items as $item) {
                    $text = is_array($item) ? trim((string) ($item['text'] ?? '')) : '';
                    if ($text === '') {
                        continue;
                    }

                    $lines[] = '- '.$text;
                }

                if ($lines === []) {
                    $lines = ['- No content captured.'];
                }
            }

            $parts[] = '## '.$section;
            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }
}
