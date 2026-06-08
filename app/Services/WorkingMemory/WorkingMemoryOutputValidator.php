<?php

namespace App\Services\WorkingMemory;

class WorkingMemoryOutputValidator
{
    /**
     * @var array<int, string>
     */
    public const REQUIRED_SECTION_KEYS = [
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
     * Validate a working-memory composition payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  float|null  $minimumCoverage  Override for the citation-coverage threshold; null uses configured default.
     * @param  int  $compactionCountInScope  Number of compactions in scope (used for unused-compaction check).
     * @param  array<int, string>|null  $requiredSections  Per-call citation-required-sections override.
     *                                                     `null` falls back to `working_memory.citation_required_sections`.
     * @param  bool  $requireSourceNotes  When true, Source Notes must contain at least one item.
     * @return array{
     *     ok: bool,
     *     message: string|null,
     *     coveragePercent: float|null,
     *     failure_type: 'hard'|'soft'|null,
     *     diagnostics: array{
     *         required_items: int,
     *         cited_items: int,
     *         compaction_cited_items: int,
     *         reason_codes: array<int, string>
     *     }
     * }
     */
    public function validate(
        array $payload,
        ?float $minimumCoverage = null,
        int $compactionCountInScope = 0,
        ?array $requiredSections = null,
        bool $requireSourceNotes = false,
    ): array {
        $sections = $payload['structured_sections'] ?? null;
        if (! is_array($sections)) {
            return $this->hardFail('Missing structured_sections payload.', 0, 0, ['empty_required_section']);
        }

        $references = $payload['references'] ?? null;
        if (! is_array($references)) {
            return $this->hardFail('References must be an array.', 0, 0, []);
        }

        $judgmentFirst = $this->isJudgmentFirstMode();
        $injectDefaultCitations = ! $judgmentFirst;

        $normalizedReferences = [];
        foreach ($references as $reference) {
            if (! is_array($reference)) {
                return $this->hardFail(
                    'Every reference must be an object-like array.',
                    0,
                    0,
                    ['invalid_link']
                );
            }

            $url = trim((string) ($reference['url'] ?? ''));
            $label = trim((string) ($reference['label'] ?? ''));

            if ($url === '' || $label === '') {
                return $this->hardFail(
                    'References must include non-empty url and label.',
                    0,
                    0,
                    ['invalid_link']
                );
            }

            if (! $this->isSupportedReferenceUrl($url)) {
                return $this->hardFail(
                    'References must include supported safe URL formats.',
                    0,
                    0,
                    ['invalid_link']
                );
            }

            $normalizedReferences[] = [
                'type' => trim((string) ($reference['type'] ?? '')),
                'url' => $url,
                'label' => $label,
            ];
        }

        $requiredItems = 0;
        $citedItems = 0;
        $compactionCitedItems = 0;
        $hasCompactionCitation = false;
        $reasonCodes = [];
        $missingSections = [];

        foreach ($this->structureRequiredSectionKeys() as $structureSection) {
            if (! array_key_exists($structureSection, $sections)) {
                $missingSections[] = $structureSection;

                continue;
            }

            $normalizedItems = $this->normalizeSectionItems(
                $sections[$structureSection],
                $normalizedReferences,
                $injectDefaultCitations,
            );
            if ($normalizedItems === []) {
                $missingSections[] = $structureSection;
            }
        }

        if ($requireSourceNotes) {
            $sourceNotes = $sections['Source Notes'] ?? null;
            $sourceItems = is_array($sourceNotes)
                ? $this->normalizeSectionItems($sourceNotes, $normalizedReferences, false)
                : [];
            if ($sourceItems === []) {
                $missingSections[] = 'Source Notes';
            }
        }

        if ($missingSections !== []) {
            return $this->hardFail(
                'Missing required sections: '.implode(', ', array_values(array_unique($missingSections))),
                $requiredItems,
                $citedItems,
                ['empty_required_section', ...$reasonCodes],
                $compactionCitedItems
            );
        }

        $citationSections = $this->citationRequiredSectionKeys($requiredSections);
        $missingCitationSections = [];

        foreach ($citationSections as $citationSection) {
            if (! array_key_exists($citationSection, $sections)) {
                $missingCitationSections[] = $citationSection;

                continue;
            }

            $normalizedItems = $this->normalizeSectionItems(
                $sections[$citationSection],
                $normalizedReferences,
                $injectDefaultCitations,
            );
            if ($normalizedItems === []) {
                $missingCitationSections[] = $citationSection;

                continue;
            }

            foreach ($normalizedItems as $item) {
                $requiredItems++;
                $citations = $this->normalizeCitations($item['citations'] ?? null);

                if ($citations === []) {
                    $reasonCodes[] = 'missing_citation';

                    continue;
                }

                if (! $this->citationsAreResolvable($citations)) {
                    $reasonCodes[] = 'invalid_link';

                    continue;
                }

                $itemText = trim((string) ($item['text'] ?? ''));
                if (! $this->bracketMarkersResolvableAgainstReferences($itemText, $normalizedReferences)) {
                    $reasonCodes[] = 'invalid_link';

                    continue;
                }

                $citedItems++;
                if ($this->containsCompactionType($citations)) {
                    $hasCompactionCitation = true;
                    $compactionCitedItems++;
                }

                foreach ($this->detectPlaceholderPatterns($itemText) as $code) {
                    $reasonCodes[] = $code;
                }
            }
        }

        if ($missingCitationSections !== []) {
            return $this->hardFail(
                'Missing citation-required sections: '.implode(', ', $missingCitationSections),
                $requiredItems,
                $citedItems,
                ['empty_required_section', ...$reasonCodes],
                $compactionCitedItems
            );
        }

        if ($judgmentFirst) {
            foreach ($this->structureRequiredSectionKeys() as $structureSection) {
                if (in_array($structureSection, $citationSections, true)) {
                    continue;
                }

                $normalizedItems = $this->normalizeSectionItems(
                    $sections[$structureSection],
                    $normalizedReferences,
                    false,
                );

                foreach ($normalizedItems as $item) {
                    $citations = $this->normalizeCitations($item['citations'] ?? null);
                    if ($citations !== [] && ! $this->citationsAreResolvable($citations)) {
                        return $this->hardFail(
                            'Citation URLs must be resolvable when provided.',
                            $requiredItems,
                            $citedItems,
                            ['invalid_link'],
                            $compactionCitedItems
                        );
                    }

                    $itemText = trim((string) ($item['text'] ?? ''));
                    if ($citations !== []
                        && ! $this->bracketMarkersResolvableAgainstReferences($itemText, $normalizedReferences)) {
                        return $this->hardFail(
                            'Bracket citation markers must resolve when citations are present.',
                            $requiredItems,
                            $citedItems,
                            ['invalid_link'],
                            $compactionCitedItems
                        );
                    }

                    foreach ($this->detectPlaceholderPatterns($itemText) as $code) {
                        $reasonCodes[] = $code;
                    }
                }
            }
        }

        $hardCitationReasons = array_values(array_intersect(
            array_unique($reasonCodes),
            ['missing_citation', 'invalid_link'],
        ));

        if ($hardCitationReasons !== [] && $citationSections !== []) {
            return $this->hardFail(
                'Citation-required section items must include resolvable citations.',
                $requiredItems,
                $citedItems,
                $hardCitationReasons,
                $compactionCitedItems
            );
        }

        $coveragePercent = $citationSections !== []
            ? $this->coveragePercent($requiredItems, $citedItems)
            : null;
        $effectiveMinimumCoverage = $minimumCoverage ?? (float) config('working_memory.citation_min_coverage', 0);

        if ($coveragePercent !== null && $effectiveMinimumCoverage > 0 && $citationSections !== []) {
            $coverageRatio = $coveragePercent / 100;

            if ($coverageRatio < $effectiveMinimumCoverage) {
                return [
                    'ok' => false,
                    'message' => sprintf(
                        'Citation coverage %.2f%% is below minimum %.2f%%.',
                        $coveragePercent,
                        $effectiveMinimumCoverage * 100
                    ),
                    'coveragePercent' => $coveragePercent,
                    'failure_type' => 'soft',
                    'diagnostics' => $this->diagnosticsPayload(
                        $requiredItems,
                        $citedItems,
                        ['coverage_below_threshold'],
                        $compactionCitedItems
                    ),
                ];
            }
        }

        if (! $judgmentFirst && $compactionCountInScope > 0 && ! $hasCompactionCitation) {
            return [
                'ok' => false,
                'message' => 'Working memory must cite at least one compaction when compactions exist in scope.',
                'coveragePercent' => $coveragePercent,
                'failure_type' => 'soft',
                'diagnostics' => $this->diagnosticsPayload(
                    $requiredItems,
                    $citedItems,
                    ['unused_compaction'],
                    $compactionCitedItems
                ),
            ];
        }

        $softReasonCodes = array_values(array_diff(array_unique($reasonCodes), ['missing_citation', 'invalid_link']));

        return [
            'ok' => true,
            'message' => null,
            'coveragePercent' => $coveragePercent,
            'failure_type' => null,
            'diagnostics' => $this->diagnosticsPayload($requiredItems, $citedItems, $softReasonCodes, $compactionCitedItems),
        ];
    }

    private function isJudgmentFirstMode(): bool
    {
        return config('working_memory.authoring_mode', 'judgment_first') !== 'strict';
    }

    /**
     * @return array<int, string>
     */
    private function structureRequiredSectionKeys(): array
    {
        $configuredSections = config('working_memory.structure_required_sections', self::REQUIRED_SECTION_KEYS);
        if (! is_array($configuredSections)) {
            return self::REQUIRED_SECTION_KEYS;
        }

        $normalizedSections = [];
        foreach ($configuredSections as $section) {
            if (! is_string($section)) {
                continue;
            }

            $normalizedSection = trim($section);
            if ($normalizedSection === '') {
                continue;
            }

            $normalizedSections[] = $normalizedSection;
        }

        return $normalizedSections !== [] ? array_values(array_unique($normalizedSections)) : self::REQUIRED_SECTION_KEYS;
    }

    /**
     * @return array<int, string>
     */
    private function citationRequiredSectionKeys(?array $override = null): array
    {
        return $this->requiredSectionKeys($override);
    }

    /**
     * @return array<int, string>
     */
    private function detectPlaceholderPatterns(string $text): array
    {
        $codes = [];
        $normalized = strtolower(trim($text));

        if ($normalized === '' || $normalized === 'tbd' || $normalized === 'todo') {
            $codes[] = 'placeholder_detected';
        }

        if (preg_match('/\b(tbd|todo)\b/i', $text) === 1) {
            $codes[] = 'placeholder_detected';
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return array<int, string>
     */
    private function requiredSectionKeys(?array $override = null): array
    {
        // Any non-null override is authoritative for this call; empty
        // normalized output disables required-section enforcement.
        if (is_array($override)) {
            $normalizedOverride = [];
            foreach ($override as $section) {
                if (! is_string($section)) {
                    continue;
                }

                $normalizedSection = trim($section);
                if ($normalizedSection === '') {
                    continue;
                }

                $normalizedOverride[] = $normalizedSection;
            }

            return array_values(array_unique($normalizedOverride));
        }

        $configuredSections = config('working_memory.citation_required_sections');
        if ($configuredSections === null) {
            return self::REQUIRED_SECTION_KEYS;
        }

        if (! is_array($configuredSections)) {
            return self::REQUIRED_SECTION_KEYS;
        }

        $normalizedSections = [];
        foreach ($configuredSections as $section) {
            if (! is_string($section)) {
                continue;
            }

            $normalizedSection = trim($section);
            if ($normalizedSection === '') {
                continue;
            }

            $normalizedSections[] = $normalizedSection;
        }

        return array_values(array_unique($normalizedSections));
    }

    /**
     * @param  array<int, array{type: string, url: string, label: string}>  $normalizedReferences
     * @return array<int, array{text: string, citations: array<int, array<string, mixed>>}>
     */
    private function normalizeSectionItems(mixed $value, array $normalizedReferences, bool $injectDefaultCitations = true): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $text = trim($entry);
                if ($text === '') {
                    continue;
                }

                $citations = $this->citationsFromBracketMarkers($text, $normalizedReferences);
                if ($citations === [] && $injectDefaultCitations && $normalizedReferences !== [] && ! preg_match('/\[\d+\]/', $text)) {
                    $citations = $this->defaultReferenceCitation($normalizedReferences);
                }

                $items[] = [
                    'text' => $text,
                    'citations' => $citations,
                ];

                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $text = trim((string) ($entry['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $hasExplicitCitationsKey = array_key_exists('citations', $entry);
            $explicit = $this->normalizeCitations($entry['citations'] ?? null);

            if ($hasExplicitCitationsKey) {
                $citations = $explicit;
            } else {
                $citations = $explicit !== []
                    ? $explicit
                    : $this->citationsFromBracketMarkers($text, $normalizedReferences);
                if ($citations === [] && $injectDefaultCitations && $normalizedReferences !== [] && ! preg_match('/\[\d+\]/', $text)) {
                    $citations = $this->defaultReferenceCitation($normalizedReferences);
                }
            }

            $items[] = [
                'text' => $text,
                'citations' => $citations,
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, array{type: string, url: string, label: string}>  $normalizedReferences
     * @return array<int, array<string, mixed>>
     */
    private function defaultReferenceCitation(array $normalizedReferences): array
    {
        $first = $normalizedReferences[0] ?? null;
        if ($first === null) {
            return [];
        }

        return $this->normalizeCitations([[
            'type' => ($first['type'] ?? '') !== '' ? $first['type'] : 'source',
            'url' => $first['url'],
            'label' => $first['label'],
        ]]);
    }

    /**
     * @param  array<int, array{type: string, url: string, label: string}>  $normalizedReferences
     * @return array<int, array<string, mixed>>
     */
    private function citationsFromBracketMarkers(string $text, array $normalizedReferences): array
    {
        if ($normalizedReferences === [] || ! preg_match_all('/\[(\d+)\]/', $text, $matches)) {
            return [];
        }

        $citations = [];
        foreach ($matches[1] as $rawIndex) {
            $index = (int) $rawIndex - 1;
            if ($index < 0 || $index >= count($normalizedReferences)) {
                return [];
            }

            $reference = $normalizedReferences[$index];
            $citations[] = [
                'type' => $reference['type'] !== '' ? $reference['type'] : 'source',
                'url' => $reference['url'],
                'label' => $reference['label'],
            ];
        }

        return $this->normalizeCitations($citations);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCitations(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $citation) {
            if (! is_array($citation)) {
                continue;
            }

            $url = trim((string) ($citation['url'] ?? ''));
            $label = trim((string) ($citation['label'] ?? ''));
            if ($url === '' || $label === '') {
                continue;
            }

            $type = trim((string) ($citation['type'] ?? ''));
            $row = [
                'type' => $type,
                'url' => $url,
                'label' => $label,
            ];

            if (array_key_exists('thought_id', $citation)) {
                $thoughtId = $citation['thought_id'];
                if ($thoughtId !== null && $thoughtId !== '') {
                    $row['thought_id'] = is_string($thoughtId) ? $thoughtId : (string) $thoughtId;
                }
            }

            if (array_key_exists('source_ref', $citation)) {
                $sourceRef = $citation['source_ref'];
                if ($sourceRef !== null && $sourceRef !== '') {
                    $row['source_ref'] = is_string($sourceRef) ? $sourceRef : (string) $sourceRef;
                }
            }

            if (array_key_exists('confidence', $citation) && is_numeric($citation['confidence'])) {
                $row['confidence'] = (float) $citation['confidence'];
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{type: string, url: string, label: string}>  $normalizedReferences
     */
    private function bracketMarkersResolvableAgainstReferences(string $text, array $normalizedReferences): bool
    {
        if ($text === '' || ! preg_match_all('/\[(\d+)\]/', $text, $matches)) {
            return true;
        }

        if ($normalizedReferences === []) {
            return false;
        }

        foreach ($matches[1] as $rawIndex) {
            $index = (int) $rawIndex - 1;
            if ($index < 0 || $index >= count($normalizedReferences)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $citations
     */
    private function citationsAreResolvable(array $citations): bool
    {
        foreach ($citations as $citation) {
            $url = trim((string) ($citation['url'] ?? ''));
            $label = trim((string) ($citation['label'] ?? ''));
            if ($url === '' || $label === '' || ! $this->isSupportedReferenceUrl($url)) {
                return false;
            }
        }

        return true;
    }

    private function isSupportedReferenceUrl(string $url): bool
    {
        $trimmedUrl = trim($url);
        if ($trimmedUrl === '') {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $trimmedUrl) === 1 || preg_match('/\s/', $trimmedUrl) === 1) {
            return false;
        }

        $parts = parse_url($trimmedUrl);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '') {
            return in_array($scheme, ['http', 'https'], true)
                && filter_var($trimmedUrl, FILTER_VALIDATE_URL) !== false;
        }

        if (str_starts_with($trimmedUrl, '/')) {
            return ! str_starts_with($trimmedUrl, '//');
        }

        if (str_contains($trimmedUrl, '..')) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._~\/#?&=%:+-]+$/', $trimmedUrl) === 1;
    }

    private function coveragePercent(int $requiredItems, int $citedItems): float
    {
        if ($requiredItems === 0) {
            return 100.0;
        }

        return round(($citedItems / $requiredItems) * 100, 2);
    }

    /**
     * @param  array<int, string>  $reasonCodes
     * @return array{
     *     required_items: int,
     *     cited_items: int,
     *     compaction_cited_items: int,
     *     reason_codes: array<int, string>
     * }
     */
    private function diagnosticsPayload(int $requiredItems, int $citedItems, array $reasonCodes, int $compactionCitedItems = 0): array
    {
        return [
            'required_items' => $requiredItems,
            'cited_items' => $citedItems,
            'compaction_cited_items' => $compactionCitedItems,
            'reason_codes' => array_values(array_unique($reasonCodes)),
        ];
    }

    /**
     * @return array{
     *     ok: false,
     *     message: string,
     *     coveragePercent: null,
     *     failure_type: 'hard',
     *     diagnostics: array{
     *         required_items: int,
     *         cited_items: int,
     *         compaction_cited_items: int,
     *         reason_codes: array<int, string>
     *     }
     * }
     */
    private function hardFail(string $message, int $requiredItems = 0, int $citedItems = 0, array $reasonCodes = [], int $compactionCitedItems = 0): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'coveragePercent' => null,
            'failure_type' => 'hard',
            'diagnostics' => $this->diagnosticsPayload($requiredItems, $citedItems, $reasonCodes, $compactionCitedItems),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $citations
     */
    private function containsCompactionType(array $citations): bool
    {
        foreach ($citations as $citation) {
            if (is_array($citation) && ($citation['type'] ?? null) === 'compaction') {
                return true;
            }
        }

        return false;
    }
}
