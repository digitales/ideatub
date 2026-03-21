<?php

namespace Tests\Unit\Services;

use App\Services\Email\EmailLinkExtractor;
use App\Services\Email\YouTubeTranscriptService;
use Mockery;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\Transcript;
use MrMySQL\YoutubeTranscript\TranscriptList;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class YouTubeTranscriptServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function success_returns_dev_note_shape(): void
    {
        $fetcher = Mockery::mock(TranscriptListFetcher::class);
        $list = Mockery::mock(TranscriptList::class);
        $transcript = Mockery::mock(Transcript::class);

        $fetcher->shouldReceive('fetch')
            ->once()
            ->with('dQw4w9WgXcQ')
            ->andReturn($list);

        $list->shouldReceive('findTranscript')
            ->once()
            ->with(['en'])
            ->andReturn($transcript);

        $transcript->language_code = 'en';
        $transcript->shouldReceive('fetch')
            ->once()
            ->withAnyArgs()
            ->andReturn([
                ['text' => 'Hello', 'start' => 0.0, 'duration' => 1.0],
                ['text' => 'world', 'start' => 1.0, 'duration' => 1.0],
            ]);

        $service = new YouTubeTranscriptService($fetcher, new EmailLinkExtractor);

        $result = $service->fetchForUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->assertTrue($result['ok']);
        $this->assertSame('dQw4w9WgXcQ', $result['video_id']);
        $this->assertSame('en', $result['language_code']);
        $this->assertSame('Hello world', $result['transcript']);
    }

    #[Test]
    public function unsupported_url_returns_recoverable_failure_reason(): void
    {
        $fetcher = Mockery::mock(TranscriptListFetcher::class);
        $fetcher->shouldReceive('fetch')->never();

        $service = new YouTubeTranscriptService($fetcher, new EmailLinkExtractor);

        $result = $service->fetchForUrl('https://example.com/not-youtube');

        $this->assertFalse($result['ok']);
        $this->assertSame('unsupported_youtube_url', $result['reason']);
        $this->assertNull($result['video_id']);
    }

    #[Test]
    public function transcript_unavailable_returns_recoverable_failure_without_throwing(): void
    {
        $fetcher = Mockery::mock(TranscriptListFetcher::class);
        $fetcher->shouldReceive('fetch')
            ->once()
            ->with('dQw4w9WgXcQ')
            ->andThrow(new TranscriptsDisabledException('dQw4w9WgXcQ'));

        $service = new YouTubeTranscriptService($fetcher, new EmailLinkExtractor);

        $result = $service->fetchForUrl('https://youtu.be/dQw4w9WgXcQ');

        $this->assertFalse($result['ok']);
        $this->assertSame('transcript_unavailable', $result['reason']);
        $this->assertSame('dQw4w9WgXcQ', $result['video_id']);
    }
}
