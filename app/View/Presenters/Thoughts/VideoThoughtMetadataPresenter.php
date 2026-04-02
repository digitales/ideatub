<?php

namespace App\View\Presenters\Thoughts;

use App\Models\Thought;
use App\Services\DemoMode;
use App\Services\Video\VideoCaptureService;
use App\Services\Video\YouTubeOEmbedService;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use Illuminate\Support\Facades\Log;

/**
 * Labeled video fields for thought detail and research “related video” cards.
 */
final class VideoThoughtMetadataPresenter
{
    use ObfuscatesDemoText;

    private function __construct(private readonly Thought $thought) {}

    public static function forVideoRoot(Thought $thought): self
    {
        return new self($thought);
    }

    /**
     * @return list<array{label: string, value: string, href: ?string}>
     */
    public function labeledRows(): array
    {
        if (data_get($this->thought->metadata, 'type') !== 'video') {
            return [];
        }

        $meta = $this->thought->metadata ?? [];
        $rows = [];

        $videoId = data_get($meta, 'video_id');
        if (is_string($videoId) && trim($videoId) !== '') {
            $rows[] = ['label' => 'Video ID', 'value' => trim($videoId), 'href' => null];
        }

        $title = data_get($meta, YouTubeOEmbedService::META_TITLE);
        if (is_string($title) && trim($title) !== '') {
            $rows[] = ['label' => 'Title', 'value' => $this->obfuscatedMetaLine($title, 'video_title'), 'href' => null];
        }

        $author = data_get($meta, YouTubeOEmbedService::META_AUTHOR_NAME);
        if (is_string($author) && trim($author) !== '') {
            $rows[] = ['label' => 'Channel', 'value' => $this->obfuscatedMetaLine($author, 'video_author_name'), 'href' => null];
        }

        $rawUrl = data_get($meta, 'video_url');
        if (is_string($rawUrl) && trim($rawUrl) !== '') {
            $rawUrl = trim($rawUrl);
            $rows[] = [
                'label' => 'URL',
                'value' => $this->safeUrlDisplay($rawUrl),
                'href' => app(DemoMode::class)->enabled() ? null : $rawUrl,
            ];
        }

        $statusLabel = VideoCaptureService::transcriptStatusHumanLabel(data_get($meta, 'transcript_status'));
        if ($statusLabel !== null) {
            $rows[] = ['label' => 'Transcript status', 'value' => $statusLabel, 'href' => null];
        }

        $sourceLabel = VideoCaptureService::transcriptSourceHumanLabel(data_get($meta, 'transcript_source'));
        if ($sourceLabel !== null) {
            $rows[] = ['label' => 'Transcript source', 'value' => $sourceLabel, 'href' => null];
        }

        $presence = $this->transcriptTextPresenceValue();
        if ($presence !== null) {
            $rows[] = ['label' => 'Transcript text', 'value' => $presence, 'href' => null];
        }

        $src = $this->thought->source;
        $capturedAs = is_string($src) && trim($src) !== '' ? trim($src) : 'video';
        $rows[] = ['label' => 'Captured as', 'value' => $capturedAs, 'href' => null];

        return $rows;
    }

    private function obfuscatedMetaLine(string $value, string $context): string
    {
        try {
            return $this->demoText(trim($value), $context) ?? trim($value);
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for video metadata line.', [
                'boundary' => 'video_thought_metadata_presenter.obfuscated_meta_line',
                'thought_id' => $this->thought->id,
                'context' => $context,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }

    private function safeUrlDisplay(string $rawUrl): string
    {
        try {
            return $this->demoText($rawUrl, 'video_canonical_url') ?? $rawUrl;
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for video metadata URL.', [
                'boundary' => 'video_thought_metadata_presenter.safe_url_display',
                'thought_id' => $this->thought->id,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }

    private function transcriptTextPresenceValue(): ?string
    {
        foreach ($this->thought->comments as $comment) {
            if (data_get($comment->metadata, 'video_section_type') !== 'transcript') {
                continue;
            }
            $raw = trim((string) ($comment->content ?? ''));
            $raw = preg_replace('/^##\s+Transcript\s*/im', '', $raw) ?? $raw;
            if (trim($raw) !== '') {
                return 'Present';
            }
        }

        return 'Not stored yet';
    }
}
