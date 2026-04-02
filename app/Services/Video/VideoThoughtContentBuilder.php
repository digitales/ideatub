<?php

namespace App\Services\Video;

/**
 * Compact plain-text body for video root thoughts (no transcript; that lives on the child).
 */
final class VideoThoughtContentBuilder
{
    /**
     * @param  array<string, mixed>  $metadata  Thought metadata; uses {@see YouTubeOEmbedService::META_TITLE} / META_AUTHOR_NAME when set.
     */
    public function rootContentFromMetadata(string $canonicalVideoUrl, string $transcriptStatus, array $metadata): string
    {
        $title = $metadata[YouTubeOEmbedService::META_TITLE] ?? null;
        $author = $metadata[YouTubeOEmbedService::META_AUTHOR_NAME] ?? null;

        return $this->rootContent(
            $canonicalVideoUrl,
            $transcriptStatus,
            is_string($title) && trim($title) !== '' ? trim($title) : null,
            is_string($author) && trim($author) !== '' ? trim($author) : null,
        );
    }

    public function rootContent(
        string $canonicalVideoUrl,
        string $transcriptStatus,
        ?string $videoTitle = null,
        ?string $videoAuthorName = null,
    ): string {
        $headline = ($videoTitle !== null && $videoTitle !== '') ? $videoTitle : 'YouTube video';
        $lines = [$headline];
        if ($videoAuthorName !== null && $videoAuthorName !== '') {
            $lines[] = $videoAuthorName;
        }
        $lines[] = '';
        $lines[] = $canonicalVideoUrl;
        $lines[] = 'Transcript status: '.$transcriptStatus;

        return implode("\n", $lines);
    }

    public function transcriptContent(string $transcriptText): string
    {
        return "## Transcript\n\n".trim($transcriptText);
    }
}
