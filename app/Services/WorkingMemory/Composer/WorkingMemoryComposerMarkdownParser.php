<?php

namespace App\Services\WorkingMemory\Composer;

/**
 * Fallback when the compose model returns markdown section headings instead of JSON.
 */
final class WorkingMemoryComposerMarkdownParser
{
    /**
     * @param  array<int, string>  $requiredSections
     * @return array{
     *     summary_markdown: string,
     *     structured_sections: array<string, array<int, array{text: string, importance: int, fallback_mode: string, citations: array<int, mixed>}>>,
     *     references: array<int, array{type: string, url: string, label: string}>
     * }|null
     */
    public static function parse(string $raw, array $requiredSections): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $sectionLookup = self::sectionLookup($requiredSections);
        if ($sectionLookup === []) {
            return null;
        }

        if (! self::looksLikeStructuredComposeMarkdown($trimmed, $sectionLookup)) {
            return null;
        }

        $structured = [];
        foreach (array_keys($sectionLookup) as $canonical) {
            $structured[$canonical] = [];
        }

        $matchedSections = 0;
        $pattern = self::sectionBlockPattern($sectionLookup);

        if (! preg_match_all($pattern, $trimmed, $matches, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($matches as $match) {
            $heading = trim((string) ($match[1] ?? ''));
            $body = (string) ($match[2] ?? '');
            $canonical = $sectionLookup[mb_strtolower($heading)] ?? null;
            if ($canonical === null) {
                continue;
            }

            $items = self::extractSectionItems($body);
            if ($items === []) {
                continue;
            }

            $matchedSections++;
            $structured[$canonical] = array_map(
                static fn (string $text): array => [
                    'text' => $text,
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [],
                ],
                $items,
            );
        }

        if ($matchedSections === 0) {
            return null;
        }

        return [
            'summary_markdown' => $trimmed,
            'structured_sections' => $structured,
            'references' => [],
        ];
    }

    /**
     * @param  array<string, string>  $sectionLookup
     */
    private static function looksLikeStructuredComposeMarkdown(string $text, array $sectionLookup): bool
    {
        $delimiter = self::sectionHeadingPrefixPattern($sectionLookup);

        return preg_match('/'.$delimiter.'/mi', $text) === 1;
    }

    /**
     * @param  array<string, string>  $sectionLookup
     */
    private static function sectionBlockPattern(array $sectionLookup): string
    {
        $heading = self::sectionHeadingPrefixPattern($sectionLookup);
        $next = self::sectionHeadingLookaheadPattern($sectionLookup);

        return '/'.$heading.'(.*?)(?='.$next.')/ms';
    }

    /**
     * Matches `## Current Focus` or `**Current Focus**` at line start (optional trailing spaces).
     *
     * @param  array<string, string>  $sectionLookup
     */
    private static function sectionHeadingPrefixPattern(array $sectionLookup): string
    {
        $names = array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            array_values($sectionLookup),
        );

        $alternation = implode('|', $names);

        return '(?:^##\s+|^\*\*)('.$alternation.')(?:\*\*)?\s*(?:\r?\n|$)';
    }

    /**
     * @param  array<string, string>  $sectionLookup
     */
    private static function sectionHeadingLookaheadPattern(array $sectionLookup): string
    {
        $names = array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            array_values($sectionLookup),
        );

        $alternation = implode('|', $names);

        return '(?:^##\s+|^\*\*)(?:'.$alternation.')(?:\*\*)?\s*(?:\r?\n|$)|\z';
    }

    /**
     * @param  array<int, string>  $requiredSections
     * @return array<string, string> lowercase heading => canonical section name
     */
    private static function sectionLookup(array $requiredSections): array
    {
        $lookup = [];
        foreach ($requiredSections as $section) {
            $name = trim($section);
            if ($name === '') {
                continue;
            }
            $lookup[mb_strtolower($name)] = $name;
        }

        return $lookup;
    }

    /**
     * @return array<int, string>
     */
    private static function extractSectionItems(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        $items = [];

        if (preg_match_all('/^\s*(?:\d+\.|[-*])\s+(.+)$/mu', $body, $matches)) {
            foreach ($matches[1] as $line) {
                $text = self::normalizeItemText((string) $line);
                if ($text !== '') {
                    $items[] = $text;
                }
            }
        }

        if ($items !== []) {
            return $items;
        }

        $paragraph = self::normalizeItemText($body);

        return $paragraph !== '' ? [$paragraph] : [];
    }

    private static function normalizeItemText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $text;
    }
}
