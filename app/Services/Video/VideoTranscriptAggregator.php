<?php

namespace App\Services\Video;

use App\Models\Thought;
use Illuminate\Support\Collection;

/**
 * Ordering and concatenation for video transcript child thoughts (single or chunked).
 */
final class VideoTranscriptAggregator
{
    /**
     * @param  iterable<int, Thought>  $thoughts
     * @return Collection<int, Thought>
     */
    public static function orderedTranscriptChildren(iterable $thoughts): Collection
    {
        return collect($thoughts)
            ->sortBy(function (Thought $t): array {
                return [
                    (int) data_get($t->metadata, 'transcript_chunk_index', 0),
                    $t->created_at?->timestamp ?? 0,
                    (string) $t->id,
                ];
            })
            ->values();
    }

    /**
     * Plain transcript body (no "## Transcript" heading), all chunks joined.
     *
     * @param  iterable<int, Thought>  $thoughts
     */
    public static function concatenatedPlainBody(iterable $thoughts): string
    {
        $parts = [];
        foreach (self::orderedTranscriptChildren($thoughts) as $child) {
            $raw = trim($child->getDecodedContent());
            $raw = preg_replace('/^##\s+Transcript\s*/im', '', $raw) ?? $raw;
            $raw = trim($raw);
            if ($raw !== '') {
                $parts[] = $raw;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Markdown block for the research prompt (includes level-2 heading).
     *
     * @param  iterable<int, Thought>  $thoughts
     */
    public static function markdownSectionForResearchPrompt(iterable $thoughts): string
    {
        $body = self::concatenatedPlainBody($thoughts);

        return $body === ''
            ? ''
            : "## Transcript (full text)\n\n".$body;
    }
}
