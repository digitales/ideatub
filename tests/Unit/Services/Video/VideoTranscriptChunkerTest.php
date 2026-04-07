<?php

namespace Tests\Unit\Services\Video;

use App\Services\Video\VideoTranscriptChunker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoTranscriptChunkerTest extends TestCase
{
    #[Test]
    public function empty_and_whitespace_yield_no_chunks(): void
    {
        $c = new VideoTranscriptChunker;

        $this->assertSame([], $c->splitPlainText(''));
        $this->assertSame([], $c->splitPlainText('   '));
    }

    #[Test]
    public function short_text_is_single_chunk(): void
    {
        $c = new VideoTranscriptChunker;

        $this->assertSame(['Hello world.'], $c->splitPlainText('Hello world.'));
    }

    #[Test]
    public function each_chunk_markdown_fits_max_stored_bytes(): void
    {
        $c = new VideoTranscriptChunker;
        $body = str_repeat('a', 200_000);
        $chunks = $c->splitPlainText($body);

        $this->assertGreaterThan(1, count($chunks));
        $prefix = "## Transcript\n\n";
        foreach ($chunks as $chunk) {
            $stored = $prefix.$chunk;
            $this->assertLessThanOrEqual(
                VideoTranscriptChunker::MAX_STORED_CONTENT_BYTES,
                strlen($stored),
            );
        }
    }
}
