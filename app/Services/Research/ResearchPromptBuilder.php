<?php

namespace App\Services\Research;

use App\Models\Thought;
use Illuminate\Support\Collection;

class ResearchPromptBuilder
{
    public const MAX_IDEA_TAGS = 10;

    public const MAX_RELATED_THOUGHTS = 3;

    public const RELATED_EXCERPT_MAX_CHARS = 500;

    public const EXISTING_RESEARCH_MAX_CHARS = 8000;

    /**
     * Assemble a bounded user prompt for the quick_brief workflow.
     *
     * @param  array<int, string>|array{includes?: array<int, string>, related_thought_ids?: array<int, string>}  $contextOptions
     * @param  array<int, string>|array{sections?: array<int, string>}  $outputShape
     * @param  iterable<int, Thought>  $relatedThoughts
     */
    public function buildQuickBriefPrompt(
        Thought $idea,
        string $instructions,
        array $contextOptions,
        array $outputShape,
        string $intensity,
        iterable $relatedThoughts = [],
        ?string $existingResearchContent = null,
    ): string {
        $flags = $this->normalizeContextFlags($contextOptions);
        $sections = $this->normalizeOutputSections($outputShape);

        $parts = [];
        $parts[] = '## Workflow';
        $parts[] = 'You are producing a **quick_brief** research note for IdeaTub. Stay factual, concise, and actionable.';

        if (in_array('idea', $flags, true)) {
            $parts[] = '## Idea';
            $parts[] = trim($idea->getDecodedContent());
        }

        $instructions = trim($instructions);
        if ($instructions !== '') {
            $parts[] = '## Skill instructions';
            $parts[] = $instructions;
        }

        $parts[] = '## Intensity';
        $parts[] = $this->formatIntensity($intensity);

        if (in_array('tags', $flags, true)) {
            $tags = $this->normalizedIdeaTags($idea);
            if ($tags !== []) {
                $parts[] = '## Idea tags';
                $parts[] = implode(', ', $tags);
            }
        }

        if (in_array('related_thoughts', $flags, true)) {
            $relatedBlock = $this->formatRelatedThoughts($relatedThoughts);
            if ($relatedBlock !== '') {
                $parts[] = '## Related context';
                $parts[] = $relatedBlock;
            }
        }

        if (in_array('existing_research', $flags, true) && $existingResearchContent !== null && trim($existingResearchContent) !== '') {
            $parts[] = '## Existing research (most recent)';
            $parts[] = $this->truncateUtf8(trim($existingResearchContent), self::EXISTING_RESEARCH_MAX_CHARS);
        }

        $parts[] = '## Requested output sections';
        $parts[] = 'Structure your answer with clear Markdown headings for: **'.implode('**, **', $sections).'**.';

        $parts[] = '## Task';
        $parts[] = 'Write the research brief now, following the sections above.';

        return implode("\n\n", array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * @param  array<int, string>|array{includes?: array<int, string>}  $contextOptions
     * @return array<int, string>
     */
    private function normalizeContextFlags(array $contextOptions): array
    {
        if ($contextOptions !== [] && $this->isStringList($contextOptions)) {
            return array_values(array_unique($contextOptions));
        }

        $includes = $contextOptions['includes'] ?? ['idea'];
        if (! is_array($includes)) {
            return ['idea'];
        }

        $out = [];
        foreach ($includes as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out !== [] ? array_values(array_unique($out)) : ['idea'];
    }

    /**
     * @param  array<int, string>|array{sections?: array<int, string>}  $outputShape
     * @return array<int, string>
     */
    private function normalizeOutputSections(array $outputShape): array
    {
        if (isset($outputShape['sections']) && is_array($outputShape['sections'])) {
            $sections = [];
            foreach ($outputShape['sections'] as $section) {
                if (! is_string($section)) {
                    continue;
                }

                $section = trim($section);
                if ($section === '') {
                    continue;
                }

                $sections[] = $section;
            }

            return $sections !== [] ? $sections : ['summary'];
        }

        if ($outputShape === []) {
            return ['summary'];
        }

        if ($this->isStringList($outputShape)) {
            $sections = [];
            foreach ($outputShape as $section) {
                $section = trim($section);
                if ($section === '') {
                    continue;
                }

                $sections[] = $section;
            }

            return $sections !== [] ? $sections : ['summary'];
        }

        return ['summary'];
    }

    /**
     * @param  array<mixed>  $arr
     */
    private function isStringList(array $arr): bool
    {
        if ($arr === [] || ! array_is_list($arr)) {
            return false;
        }

        foreach ($arr as $v) {
            if (! is_string($v)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function normalizedIdeaTags(Thought $idea): array
    {
        $raw = $idea->metadata['tags'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $t) {
            if (! is_string($t)) {
                continue;
            }
            $n = trim($t);
            if ($n === '') {
                continue;
            }
            $tags[$n] = $n;
            if (count($tags) >= self::MAX_IDEA_TAGS) {
                break;
            }
        }

        return array_values($tags);
    }

    private function formatIntensity(string $intensity): string
    {
        $i = trim($intensity);

        return match ($i) {
            'concise' => 'concise: keep the brief short; prioritize signal over breadth.',
            'thorough' => 'thorough: cover more angles while staying structured; still avoid rambling.',
            default => 'standard: balanced depth and length.',
        };
    }

    /**
     * @param  iterable<int, Thought>  $relatedThoughts
     */
    private function formatRelatedThoughts(iterable $relatedThoughts): string
    {
        $collection = $relatedThoughts instanceof Collection
            ? $relatedThoughts
            : collect($relatedThoughts);

        $lines = [];
        $i = 0;
        foreach ($collection as $thought) {
            if (! $thought instanceof Thought) {
                continue;
            }
            $i++;
            if ($i > self::MAX_RELATED_THOUGHTS) {
                break;
            }
            $flat = str_replace(["\r\n", "\r", "\n"], ' ', $thought->getDecodedContent());
            $flat = trim(preg_replace('/\s+/', ' ', $flat) ?? $flat);
            $excerpt = $this->truncateUtf8($flat, self::RELATED_EXCERPT_MAX_CHARS);
            $lines[] = 'Related thought '.$i.': '.$excerpt;
        }

        return implode("\n", $lines);
    }

    private function truncateUtf8(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        if ($maxChars <= 1) {
            return '…';
        }

        return rtrim(mb_substr($text, 0, $maxChars - 1)).'…';
    }
}
