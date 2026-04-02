<?php

namespace Tests\Unit\Services\Video;

use App\Services\Video\YouTubeOEmbedService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeOEmbedServiceTest extends TestCase
{
    public function test_enrich_merges_title_and_author_when_oembed_succeeds(): void
    {
        Http::fake([
            'www.youtube.com/oembed*' => Http::response([
                'title' => 'Example title',
                'author_name' => 'Example channel',
            ], 200),
        ]);

        $service = new YouTubeOEmbedService;
        $out = $service->enrichVideoMetadataIfMissing(
            ['type' => 'video'],
            'https://www.youtube.com/watch?v=abc12345678'
        );

        $this->assertSame('Example title', $out[YouTubeOEmbedService::META_TITLE]);
        $this->assertSame('Example channel', $out[YouTubeOEmbedService::META_AUTHOR_NAME]);
    }

    public function test_enrich_skips_http_when_title_already_present(): void
    {
        Http::fake();

        $service = new YouTubeOEmbedService;
        $out = $service->enrichVideoMetadataIfMissing(
            [
                'type' => 'video',
                YouTubeOEmbedService::META_TITLE => 'Existing',
            ],
            'https://www.youtube.com/watch?v=abc12345678'
        );

        $this->assertSame('Existing', $out[YouTubeOEmbedService::META_TITLE]);
        Http::assertNothingSent();
    }
}
