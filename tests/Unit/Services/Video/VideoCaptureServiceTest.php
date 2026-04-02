<?php

namespace Tests\Unit\Services\Video;

use App\Models\Thought;
use App\Models\User;
use App\Services\Email\EmailLinkExtractor;
use App\Services\OpenRouterService;
use App\Services\Video\VideoCaptureService;
use App\Services\Video\VideoThoughtContentBuilder;
use App\Services\Video\YouTubeOEmbedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'www.youtube.com/oembed*' => Http::response('', 404),
        ]);
    }

    private function fakeEmbedding(): array
    {
        return array_fill(0, 1536, 0.02);
    }

    private function serviceWithOpenRouter(OpenRouterService $openRouter): VideoCaptureService
    {
        return new VideoCaptureService(
            new EmailLinkExtractor,
            $openRouter,
            new VideoThoughtContentBuilder,
            new YouTubeOEmbedService,
        );
    }

    #[Test]
    public function reuses_existing_root_for_same_user_and_video_id(): void
    {
        $user = User::factory()->create();
        $embed = $this->fakeEmbedding();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->twice()->andReturn($embed);

        $service = $this->serviceWithOpenRouter($openRouter);

        $first = $service->capture($user, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $second = $service->capture($user, 'https://youtu.be/dQw4w9WgXcQ?t=12');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Thought::query()->whereNull('parent_id')->where('user_id', $user->id)->count());
    }

    #[Test]
    #[DataProvider('canonicalUrlProvider')]
    public function persists_canonical_watch_url_for_various_input_shapes(string $input, string $expectedId): void
    {
        $user = User::factory()->create();
        $embed = $this->fakeEmbedding();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->once()->andReturn($embed);

        $service = $this->serviceWithOpenRouter($openRouter);
        $root = $service->capture($user, $input);

        $canonical = 'https://www.youtube.com/watch?v='.$expectedId;
        $this->assertSame($canonical, $root->metadata['video_url']);
        $this->assertSame($expectedId, $root->metadata['video_id']);
        $this->assertStringContainsString($canonical, $root->content);
        $this->assertStringContainsString('Transcript status: pending', $root->content);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function canonicalUrlProvider(): iterable
    {
        yield 'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLx', 'dQw4w9WgXcQ'];
        yield 'youtu_be' => ['https://youtu.be/dQw4w9WgXcQ?t=10', 'dQw4w9WgXcQ'];
        yield 'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'];
        yield 'live' => ['https://www.youtube.com/live/dQw4w9WgXcQ?feature=share', 'dQw4w9WgXcQ'];
    }

    #[Test]
    public function capture_without_transcript_sets_pending_and_none_on_root(): void
    {
        $user = User::factory()->create();
        $embed = $this->fakeEmbedding();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->once()->andReturn($embed);

        $service = $this->serviceWithOpenRouter($openRouter);
        $root = $service->capture($user, 'https://www.youtube.com/watch?v=abc12345678');

        $this->assertSame('pending', $root->metadata['transcript_status']);
        $this->assertSame('none', $root->metadata['transcript_source']);
        $this->assertSame('video', $root->metadata['type']);
        $this->assertStringContainsString('Transcript status: pending', $root->content);
    }

    #[Test]
    public function recapture_without_transcript_when_transcript_child_exists_does_not_reset_to_pending(): void
    {
        $user = User::factory()->create();
        $embed = $this->fakeEmbedding();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->times(3)->andReturn($embed);

        $service = $this->serviceWithOpenRouter($openRouter);

        $root = $service->capture($user, 'https://www.youtube.com/watch?v=abc12345678', 'hello transcript');
        $this->assertSame('manual', $root->metadata['transcript_status']);
        $this->assertSame('pasted', $root->metadata['transcript_source']);
        $this->assertStringContainsString('Transcript status: manual', $root->content);

        $rootAgain = $service->capture($user, 'https://youtu.be/abc12345678');
        $this->assertSame('manual', $rootAgain->metadata['transcript_status']);
        $this->assertSame('pasted', $rootAgain->metadata['transcript_source']);
        $this->assertStringContainsString('Transcript status: manual', $rootAgain->content);
    }

    #[Test]
    public function transcript_child_has_video_section_type_metadata(): void
    {
        $user = User::factory()->create();
        $embed = $this->fakeEmbedding();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->twice()->andReturn($embed);

        $service = $this->serviceWithOpenRouter($openRouter);
        $root = $service->capture($user, 'https://www.youtube.com/watch?v=abc12345678', 'segment one');

        $child = Thought::query()
            ->where('parent_id', $root->id)
            ->where('metadata->video_section_type', 'transcript')
            ->sole();

        $this->assertSame('transcript', $child->metadata['video_section_type']);
        $this->assertSame("## Transcript\n\nsegment one", $child->content);
    }

    #[Test]
    public function transcript_upsert_replaces_in_place_without_extra_children(): void
    {
        $user = User::factory()->create();
        $embed = $this->fakeEmbedding();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->times(4)->andReturn($embed);

        $service = $this->serviceWithOpenRouter($openRouter);

        $root = $service->capture($user, 'https://www.youtube.com/watch?v=abc12345678', 'first');
        $firstChildId = Thought::query()->where('parent_id', $root->id)->sole()->id;

        $rootAgain = $service->capture($user, 'https://www.youtube.com/watch?v=abc12345678', 'second version');
        $this->assertSame($root->id, $rootAgain->id);

        $children = Thought::query()
            ->where('parent_id', $root->id)
            ->where('metadata->video_section_type', 'transcript')
            ->get();

        $this->assertCount(1, $children);
        $this->assertSame($firstChildId, $children->first()->id);
        $this->assertSame("## Transcript\n\nsecond version", $children->first()->content);
    }

    #[Test]
    public function recapture_preserves_non_capture_metadata_on_root(): void
    {
        $user = User::factory()->create();
        $canonicalUrl = 'https://www.youtube.com/watch?v=abc12345678';

        $root = Thought::query()->create([
            'content' => 'old root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'pending',
                'transcript_source' => 'none',
                'research_pending' => true,
                'research_thought_id' => 'research-123',
                'transcript_error_reason' => 'temporary outage',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->once()->andReturn($this->fakeEmbedding());

        $service = $this->serviceWithOpenRouter($openRouter);
        $captured = $service->capture($user, $canonicalUrl);

        $this->assertSame($root->id, $captured->id);
        $this->assertTrue($captured->metadata['research_pending']);
        $this->assertSame('research-123', $captured->metadata['research_thought_id']);
        $this->assertSame('temporary outage', $captured->metadata['transcript_error_reason']);
        $this->assertSame('pending', $captured->metadata['transcript_status']);
        $this->assertSame('none', $captured->metadata['transcript_source']);
    }

    #[Test]
    public function manual_transcript_capture_clears_stale_transcript_error_reason(): void
    {
        $user = User::factory()->create();
        $canonicalUrl = 'https://www.youtube.com/watch?v=abc12345678';

        $root = Thought::query()->create([
            'content' => 'old root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'pending',
                'transcript_source' => 'none',
                'transcript_error_reason' => 'timeout',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->twice()->andReturn($this->fakeEmbedding());

        $service = $this->serviceWithOpenRouter($openRouter);
        $captured = $service->capture($user, $canonicalUrl, 'manual transcript');

        $this->assertSame($root->id, $captured->id);
        $this->assertSame('manual', $captured->metadata['transcript_status']);
        $this->assertSame('pasted', $captured->metadata['transcript_source']);
        $this->assertArrayNotHasKey('transcript_error_reason', $captured->metadata);
    }

    #[Test]
    public function duplicate_root_merge_reparents_non_transcript_children_to_surviving_root(): void
    {
        $user = User::factory()->create();
        $canonicalUrl = 'https://www.youtube.com/watch?v=abc12345678';

        $firstRoot = Thought::query()->create([
            'content' => 'older root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'pending',
                'transcript_source' => 'none',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $duplicateRoot = Thought::query()->create([
            'content' => 'newer duplicate root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'pending',
                'transcript_source' => 'none',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $commentChild = Thought::query()->create([
            'content' => 'follow-up comment',
            'embedding' => null,
            'metadata' => [
                'type' => 'note',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => $duplicateRoot->id,
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->once()->andReturn($this->fakeEmbedding());

        $service = $this->serviceWithOpenRouter($openRouter);
        $captured = $service->capture($user, $canonicalUrl);

        $this->assertSame($firstRoot->id, $captured->id);
        $this->assertDatabaseMissing('thoughts', ['id' => $duplicateRoot->id]);
        $this->assertDatabaseHas('thoughts', [
            'id' => $commentChild->id,
            'parent_id' => $firstRoot->id,
        ]);
    }

    #[Test]
    public function duplicate_root_merge_prefers_newer_non_capture_metadata_values(): void
    {
        $user = User::factory()->create();
        $canonicalUrl = 'https://www.youtube.com/watch?v=abc12345678';

        Thought::query()->create([
            'content' => 'older root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'pending',
                'transcript_source' => 'none',
                'research_pending' => false,
                'research_thought_id' => 'research-old',
                'transcript_error_reason' => 'older error',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $newerRoot = Thought::query()->create([
            'content' => 'newer root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'pending',
                'transcript_source' => 'none',
                'research_pending' => true,
                'research_thought_id' => 'research-new',
                'transcript_error_reason' => 'newer error',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->once()->andReturn($this->fakeEmbedding());

        $service = $this->serviceWithOpenRouter($openRouter);
        $captured = $service->capture($user, $canonicalUrl);

        $this->assertDatabaseMissing('thoughts', ['id' => $newerRoot->id]);
        $this->assertTrue($captured->metadata['research_pending']);
        $this->assertSame('research-new', $captured->metadata['research_thought_id']);
        $this->assertSame('newer error', $captured->metadata['transcript_error_reason']);
    }

    #[Test]
    public function duplicate_root_merge_preserves_newer_source_metadata(): void
    {
        $user = User::factory()->create();
        $canonicalUrl = 'https://www.youtube.com/watch?v=abc12345678';

        Thought::query()->create([
            'content' => 'older root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'pending',
                'transcript_source' => 'none',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => [
                'imported_email_id' => 12,
                'capture_context' => 'older',
            ],
            'parent_id' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $newerRoot = Thought::query()->create([
            'content' => 'newer root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'pending',
                'transcript_source' => 'none',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => [
                'capture_context' => 'newer',
                'sync_state' => 'complete',
            ],
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->once()->andReturn($this->fakeEmbedding());

        $service = $this->serviceWithOpenRouter($openRouter);
        $captured = $service->capture($user, $canonicalUrl);

        $this->assertDatabaseMissing('thoughts', ['id' => $newerRoot->id]);
        $this->assertSame([
            'imported_email_id' => 12,
            'capture_context' => 'newer',
            'sync_state' => 'complete',
        ], $captured->source_metadata);
    }

    #[Test]
    public function capture_with_transcript_reparents_kept_child_from_duplicate_root_before_cleanup(): void
    {
        $user = User::factory()->create();
        $canonicalUrl = 'https://www.youtube.com/watch?v=abc12345678';

        $firstRoot = Thought::query()->create([
            'content' => 'older root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'pending',
                'transcript_source' => 'none',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $duplicateRoot = Thought::query()->create([
            'content' => 'newer duplicate root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'manual',
                'transcript_source' => 'pasted',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $keptTranscript = Thought::query()->create([
            'content' => 'old transcript on duplicate root',
            'embedding' => null,
            'metadata' => [
                'video_section_type' => 'transcript',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => $duplicateRoot->id,
        ]);

        $extraTranscript = Thought::query()->create([
            'content' => 'extra transcript',
            'embedding' => null,
            'metadata' => [
                'video_section_type' => 'transcript',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => $duplicateRoot->id,
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->twice()->andReturn($this->fakeEmbedding());

        $service = $this->serviceWithOpenRouter($openRouter);
        $captured = $service->capture($user, $canonicalUrl, 'replacement transcript');

        $this->assertSame($firstRoot->id, $captured->id);
        $this->assertDatabaseMissing('thoughts', ['id' => $duplicateRoot->id]);
        $this->assertDatabaseMissing('thoughts', ['id' => $extraTranscript->id]);
        $this->assertDatabaseHas('thoughts', [
            'id' => $keptTranscript->id,
            'parent_id' => $firstRoot->id,
        ]);
        $children = Thought::query()
            ->where('parent_id', $firstRoot->id)
            ->where('metadata->video_section_type', 'transcript')
            ->get();
        $this->assertCount(1, $children);
        $this->assertSame($keptTranscript->id, $children->sole()->id);
        $this->assertSame("## Transcript\n\nreplacement transcript", $children->sole()->content);
    }

    #[Test]
    public function recapture_consolidates_duplicate_roots_and_preserves_existing_transcript_child(): void
    {
        $user = User::factory()->create();
        $canonicalUrl = 'https://www.youtube.com/watch?v=abc12345678';

        $firstRoot = Thought::query()->create([
            'content' => 'older root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'pending',
                'transcript_source' => 'none',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $duplicateRoot = Thought::query()->create([
            'content' => 'newer duplicate root',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonicalUrl,
                'transcript_status' => 'manual',
                'transcript_source' => 'pasted',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transcriptChild = Thought::query()->create([
            'content' => 'existing transcript',
            'embedding' => null,
            'metadata' => [
                'video_section_type' => 'transcript',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => $duplicateRoot->id,
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->once()->andReturn($this->fakeEmbedding());

        $service = $this->serviceWithOpenRouter($openRouter);
        $captured = $service->capture($user, $canonicalUrl);

        $this->assertSame($firstRoot->id, $captured->id);
        $this->assertDatabaseMissing('thoughts', ['id' => $duplicateRoot->id]);
        $this->assertDatabaseHas('thoughts', [
            'id' => $transcriptChild->id,
            'parent_id' => $firstRoot->id,
        ]);
        $this->assertSame('manual', $captured->metadata['transcript_status']);
        $this->assertSame('pasted', $captured->metadata['transcript_source']);
        $this->assertStringContainsString('Transcript status: manual', $captured->content);
        $this->assertSame(1, Thought::query()->whereNull('parent_id')->where('user_id', $user->id)->count());
    }

    #[Test]
    public function rejects_non_youtube_url(): void
    {
        $user = User::factory()->create();
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('embed');

        $service = $this->serviceWithOpenRouter($openRouter);

        $this->expectException(\InvalidArgumentException::class);
        $service->capture($user, 'https://example.com/page');
    }
}
