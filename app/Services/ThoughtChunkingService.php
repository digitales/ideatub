<?php

namespace App\Services;

/**
 * Chunks long document content at markdown headings for storage as multiple linked thoughts.
 * Used by capture_plan (and can be reused by other thought capture flows) when content
 * exceeds the word threshold and no_chunking is not set.
 */
class ThoughtChunkingService
{
    public const CHUNK_WORD_THRESHOLD = 500;

    /**
     * Whether chunking should be applied: content exceeds word threshold and opt-out is not set.
     *
     * @param  array<string, mixed>  $options  May contain: no_chunking (bool), no-chunking (bool), or source_metadata (array with no_chunking)
     */
    public function shouldChunk(string $content, array $options = []): bool
    {
        if ($this->isNoChunkingRequested($options)) {
            return false;
        }

        return $this->wordCount($content) > self::CHUNK_WORD_THRESHOLD;
    }

    /**
     * Check if the caller requested no chunking (metadata or settings).
     *
     * @param  array<string, mixed>  $options
     */
    public function isNoChunkingRequested(array $options = []): bool
    {
        if (isset($options['no_chunking']) && $options['no_chunking']) {
            return true;
        }
        if (isset($options['no-chunking']) && $options['no-chunking']) {
            return true;
        }
        $metadata = $options['source_metadata'] ?? null;
        if (is_array($metadata) && ! empty($metadata['no_chunking'])) {
            return true;
        }
        if (is_array($metadata) && ! empty($metadata['no-chunking'])) {
            return true;
        }

        return false;
    }

    public function wordCount(string $content): int
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Split content at markdown ATX headings (##, ###, etc.). Content before the first heading is the first section.
     *
     * @return array<int, array{title: string, content: string}>
     */
    public function splitAtHeadings(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [['title' => 'Intro', 'content' => '']];
        }

        // Match lines that are ATX-style headings: optional leading whitespace, 1-6 #, space, rest of line
        $pattern = '/^(#{1,6})\s+(.+)$/m';
        $sections = [];
        $lastEnd = 0;
        $lastTitle = 'Intro';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false || count($matches) === 0) {
            return [['title' => 'Intro', 'content' => $content]];
        }

        foreach ($matches as $match) {
            $fullMatch = $match[0][1];
            $title = trim($match[2][0]);
            $chunkContent = trim(substr($content, $lastEnd, $fullMatch - $lastEnd));
            $sections[] = ['title' => $lastTitle, 'content' => $chunkContent];
            $lastEnd = $fullMatch;
            $lastTitle = $title;
        }

        $finalChunk = trim(substr($content, $lastEnd));
        $sections[] = ['title' => $lastTitle, 'content' => $finalChunk];

        // If the document starts with a heading (e.g. # Title), the first section is empty.
        // Merge it with the next so the root thought is never blank.
        if (count($sections) > 1 && trim($sections[0]['content']) === '') {
            $sections[1]['title'] = $sections[0]['title'] !== 'Intro' ? $sections[0]['title'] : $sections[1]['title'];
            $sections[1]['content'] = $sections[0]['content'].$sections[1]['content'];
            array_shift($sections);
        }

        return $sections;
    }
}
