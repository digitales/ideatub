<?php

namespace App\Services\Meetings;

use App\Models\Thought;

class MeetingPromptBuilder
{
    public const MAX_TRANSCRIPT_CHARS = 24000;

    public const MAX_CONTEXT_THOUGHTS = 3;

    public const MAX_CONTEXT_EXCERPT_CHARS = 500;

    /**
     * Assemble a bounded user prompt for the meeting_brief workflow.
     *
     * @param  array<int, string>  $coreCategories
     * @param  array<int, string>  $customCategories
     * @param  iterable<int, Thought>  $relatedThoughts
     */
    public function buildMeetingBriefPrompt(
        Thought $meeting,
        string $instructions,
        string $transcriptText,
        string $intensity,
        array $coreCategories,
        array $customCategories,
        array $outputShape = [],
        iterable $relatedThoughts = [],
    ): string {
        $core = $this->normalizeStringList($coreCategories, MeetingSkillManager::DEFAULT_CORE_CATEGORIES);
        $custom = $this->normalizeStringList($customCategories, []);
        $sections = $this->normalizeOutputSections($outputShape);
        $transcript = $this->truncateUtf8(trim($transcriptText), self::MAX_TRANSCRIPT_CHARS);

        $parts = [];
        $parts[] = '## Workflow';
        $parts[] = 'You are producing a **meeting_brief** analysis for IdeaTub. Return only valid JSON (no Markdown).';

        $parts[] = '## Meeting context';
        $parts[] = trim($meeting->getDecodedContent());

        $instructions = trim($instructions);
        if ($instructions !== '') {
            $parts[] = '## Skill instructions';
            $parts[] = $instructions;
        }

        $parts[] = '## Intensity';
        $parts[] = $this->formatIntensity($intensity);

        if (trim($transcript) !== '') {
            $parts[] = '## Transcript';
            $parts[] = $transcript;
        }

        $related = $this->formatRelatedThoughts($relatedThoughts);
        if ($related !== '') {
            $parts[] = '## Related meetings';
            $parts[] = $related;
        }

        $parts[] = '## Output contract';
        $parts[] = implode("\n", [
            'Reply with a single JSON object using this exact shape:',
            '{',
            '  "summary": "string",',
            '  "core_categories": {',
            '    "decisions": ["string"],',
            '    "action_items": [',
            '      {"task":"string","owner":"string|null","due_date":"string|null","confidence":"high|medium|low"}',
            '    ],',
            '    "risks": ["string"],',
            '    "blockers": ["string"],',
            '    "follow_ups": ["string"]',
            '  },',
            '  "custom_sections": {',
            '    "customCategoryName": ["string"]',
            '  },',
            '  "requested_sections": {',
            '    "section_name": "string" ',
            '  }',
            '}',
            'Always include every core category key even when empty.',
            'Use these core categories: '.implode(', ', $core).'.',
            'Use these optional custom categories: '.($custom === [] ? '(none)' : implode(', ', $custom)).'.',
            'requested_sections keys must include (in this order): '.implode(', ', $sections).'.',
            'You may add other meeting-relevant sections based on content (e.g. dependencies, risks_overview, decisions_rationale), but keep actions and conclusion as the final two keys in requested_sections.',
            'actions should capture practical next steps; conclusion should close with overall takeaway and confidence.',
        ]);

        $parts[] = '## Task';
        $parts[] = 'Generate the meeting analysis JSON now.';

        return implode("\n\n", array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * @param  array<int, string>|array{sections?: array<int, string>}  $outputShape
     * @return array<int, string>
     */
    private function normalizeOutputSections(array $outputShape): array
    {
        if (isset($outputShape['sections']) && is_array($outputShape['sections'])) {
            return $this->normalizeStringList($outputShape['sections'], MeetingSkillManager::DEFAULT_OUTPUT_SECTIONS);
        }

        return $this->normalizeStringList($outputShape, MeetingSkillManager::DEFAULT_OUTPUT_SECTIONS);
    }

    /**
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $normalized = trim($item);
            if ($normalized === '') {
                continue;
            }

            $out[$normalized] = $normalized;
        }

        $normalized = array_values($out);

        return $normalized !== [] ? $normalized : $fallback;
    }

    /**
     * @param  iterable<int, Thought>  $relatedThoughts
     */
    private function formatRelatedThoughts(iterable $relatedThoughts): string
    {
        $lines = [];
        $count = 0;

        foreach ($relatedThoughts as $thought) {
            if (! $thought instanceof Thought) {
                continue;
            }

            $count++;
            if ($count > self::MAX_CONTEXT_THOUGHTS) {
                break;
            }

            $flat = str_replace(["\r\n", "\r", "\n"], ' ', $thought->getDecodedContent());
            $flat = trim(preg_replace('/\s+/', ' ', $flat) ?? $flat);
            $excerpt = $this->truncateUtf8($flat, self::MAX_CONTEXT_EXCERPT_CHARS);
            $lines[] = 'Related meeting '.$count.': '.$excerpt;
        }

        return implode("\n", $lines);
    }

    private function formatIntensity(string $intensity): string
    {
        $i = trim($intensity);

        return match ($i) {
            'concise' => 'concise: prioritize crisp bullets and only essential detail.',
            'thorough' => 'thorough: include fuller rationale and implications while staying structured.',
            default => 'standard: balanced depth and brevity.',
        };
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
