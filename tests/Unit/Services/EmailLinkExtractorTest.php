<?php

namespace Tests\Unit\Services;

use App\Services\Email\EmailLinkExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailLinkExtractorTest extends TestCase
{
    #[Test]
    public function extracts_unique_normalized_urls_from_plain_text_and_html(): void
    {
        $extractor = new EmailLinkExtractor;

        $plain = <<<'TXT'
See https://EXAMPLE.com/path and https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLx
Also https://youtu.be/dQw4w9WgXcQ?t=10 (duplicate video id).
TXT;
        $html = '<p><a href="https://Other.Example.COM/foo">x</a> <a href="https://example.com/path">dup</a></p>';

        $links = $extractor->extractFromContent($plain, $html);

        $urls = array_column($links, 'url');
        $this->assertContains('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $urls);
        $this->assertContains('https://other.example.com/foo', $urls);
        $youtubeRows = array_values(array_filter($links, fn (array $r) => $r['type'] === 'youtube'));
        $this->assertCount(1, $youtubeRows);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $youtubeRows[0]['url']);
        $genericPaths = array_column(array_filter($links, fn (array $r) => $r['type'] === 'generic'), 'url');
        $this->assertContains('https://example.com/path', $genericPaths);
        $this->assertContains('https://other.example.com/foo', $genericPaths);
        $this->assertSame(count(array_unique($urls)), count($urls));
    }

    #[Test]
    public function preserves_non_default_ports_in_normalized_generic_urls(): void
    {
        $extractor = new EmailLinkExtractor;

        $links = $extractor->extractFromContent(
            'Ports: https://Example.com:8443/path and https://example.com:8443/path.',
        );

        $this->assertSame([
            ['url' => 'https://example.com:8443/path', 'type' => 'generic'],
        ], $links);
    }

    #[Test]
    public function extracts_youtube_video_id_from_live_urls(): void
    {
        $extractor = new EmailLinkExtractor;

        $this->assertSame(
            'dQw4w9WgXcQ',
            $extractor->extractYouTubeVideoId('https://www.youtube.com/live/dQw4w9WgXcQ?feature=share')
        );
    }

    #[Test]
    public function extracts_youtube_video_id_from_shorts_urls(): void
    {
        $extractor = new EmailLinkExtractor;

        $this->assertSame(
            'dQw4w9WgXcQ',
            $extractor->extractYouTubeVideoId('https://www.youtube.com/shorts/dQw4w9WgXcQ')
        );
    }

    #[Test]
    public function extracts_youtube_video_id_from_youtu_be_links(): void
    {
        $extractor = new EmailLinkExtractor;

        $this->assertSame(
            'dQw4w9WgXcQ',
            $extractor->extractYouTubeVideoId('https://youtu.be/dQw4w9WgXcQ?t=42')
        );
    }

    #[Test]
    public function does_not_extract_youtube_video_id_when_trailing_path_prose_follows_the_video_id(): void
    {
        $extractor = new EmailLinkExtractor;

        $this->assertNull(
            $extractor->extractYouTubeVideoId('https://youtu.be/dQw4w9WgXcQNotes')
        );
        $this->assertNull(
            $extractor->extractYouTubeVideoId('https://www.youtube.com/shorts/dQw4w9WgXcQNotes')
        );
        $this->assertNull(
            $extractor->extractYouTubeVideoId('https://www.youtube.com/live/dQw4w9WgXcQNotes')
        );
    }

    #[Test]
    public function links_from_processing_metadata_returns_stored_extracted_links_for_review_items(): void
    {
        $extractor = new EmailLinkExtractor;

        $stored = [
            'extracted_links' => [
                ['url' => 'https://www.youtube.com/watch?v=abc12345678', 'type' => 'youtube'],
                ['url' => 'https://example.com/post', 'type' => 'generic'],
            ],
            'other' => 1,
        ];

        $this->assertSame($stored['extracted_links'], $extractor->linksFromProcessingMetadata($stored));
        $this->assertSame([], $extractor->linksFromProcessingMetadata(null));
        $this->assertSame([], $extractor->linksFromProcessingMetadata([]));
        $this->assertSame([], $extractor->linksFromProcessingMetadata(['extracted_links' => null]));
    }
}
