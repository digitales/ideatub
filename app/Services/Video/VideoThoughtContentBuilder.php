<?php

namespace App\Services\Video;

/**
 * Compact plain-text body for video root thoughts (no transcript; that lives on the child).
 */
final class VideoThoughtContentBuilder
{
    public function rootContent(string $canonicalVideoUrl, string $transcriptStatus): string
    {
        return "YouTube video\n\n{$canonicalVideoUrl}\nTranscript status: {$transcriptStatus}";
    }

    public function transcriptContent(string $transcriptText): string
    {
        return "## Transcript\n\n".trim($transcriptText);
    }
}
