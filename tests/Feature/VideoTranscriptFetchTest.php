<?php

namespace Tests\Feature;

use App\Jobs\FetchVideoTranscript;
use App\Models\Thought;
use App\Models\User;
use App\Services\Email\YouTubeTranscriptService;
use App\Services\OpenRouterService;
use App\Services\Video\VideoCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class VideoTranscriptFetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @param  array<string, mixed>  $metadataExtra
     */
    private function createVideoRoot(User $user, array $metadataExtra = []): Thought
    {
        $canonical = 'https://www.youtube.com/watch?v=abc12345678';

        return Thought::query()->create([
            'content' => 'YouTube video placeholder',
            'embedding' => null,
            'metadata' => array_merge([
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => $canonical,
                'transcript_status' => 'pending',
                'transcript_source' => 'none',
            ], $metadataExtra),
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
        ]);
    }

    private function bindOpenRouterForEmbeds(int $times): void
    {
        $embed = array_fill(0, 1536, 0.02);
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('embed')->times($times)->andReturn($embed);
        $this->app->instance(OpenRouterService::class, $mock);
    }

    /**
     * When Queue::fake() is active, {@see Bus::dispatchSync()} may not run the job body; call handle directly.
     */
    private function runFetchVideoTranscriptJob(string $rootId, bool $researchNow): void
    {
        $job = new FetchVideoTranscript($rootId, $researchNow);
        $this->app->call([$job, 'handle']);
    }

    public function test_fetch_transcript_success_upserts_youtube_child_and_updates_root(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user);

        $this->bindOpenRouterForEmbeds(2);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')
            ->once()
            ->with('https://www.youtube.com/watch?v=abc12345678')
            ->andReturn([
                'ok' => true,
                'video_id' => 'abc12345678',
                'language_code' => 'en',
                'transcript' => 'Line one. Line two.',
            ]);
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertSame('available', $root->metadata['transcript_status']);
        $this->assertSame('youtube', $root->metadata['transcript_source']);
        $this->assertArrayNotHasKey('transcript_error_reason', $root->metadata);
        $this->assertArrayNotHasKey(VideoCaptureService::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH, $root->metadata);
        $this->assertStringContainsString('Transcript status: available', $root->content);

        $child = Thought::query()
            ->where('parent_id', $root->id)
            ->where('metadata->video_section_type', 'transcript')
            ->sole();

        $this->assertSame('youtube', $child->source);
        $this->assertSame("## Transcript\n\nLine one. Line two.", $child->content);
    }

    public function test_missing_video_url_still_fetches_using_canonical_url_from_video_id(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'video_url' => null,
        ]);

        $this->bindOpenRouterForEmbeds(2);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')
            ->once()
            ->with('https://www.youtube.com/watch?v=abc12345678')
            ->andReturn([
                'ok' => true,
                'video_id' => 'abc12345678',
                'language_code' => 'en',
                'transcript' => 'Recovered from id.',
            ]);
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertSame('available', $root->metadata['transcript_status']);
        $this->assertSame('https://www.youtube.com/watch?v=abc12345678', $root->metadata['video_url']);
    }

    public function test_fetch_transcript_unavailable_sets_status_and_clears_error_reason(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'transcript_error_reason' => 'stale',
        ]);

        $this->bindOpenRouterForEmbeds(1);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => false,
            'reason' => 'transcript_unavailable',
            'video_id' => 'abc12345678',
        ]);
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertSame('unavailable', $root->metadata['transcript_status']);
        $this->assertSame('none', $root->metadata['transcript_source']);
        $this->assertArrayNotHasKey('transcript_error_reason', $root->metadata);
    }

    public function test_fetch_transcript_failed_sets_bounded_error_reason(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user);

        $this->bindOpenRouterForEmbeds(1);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => false,
            'reason' => 'youtube_rate_limited',
            'video_id' => 'abc12345678',
        ]);
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertSame('failed', $root->metadata['transcript_status']);
        $this->assertSame('none', $root->metadata['transcript_source']);
        $this->assertSame('youtube_rate_limited', $root->metadata['transcript_error_reason']);
    }

    public function test_non_video_thought_exits_without_fetch(): void
    {
        $user = User::factory()->create();
        $thought = Thought::query()->create([
            'content' => 'Not a video',
            'embedding' => null,
            'metadata' => [
                'type' => 'idea',
            ],
            'user_id' => $user->id,
            'source' => 'manual',
            'source_metadata' => null,
            'parent_id' => null,
        ]);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldNotReceive('fetchForUrl');
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('embed');
        $this->app->instance(OpenRouterService::class, $openRouter);

        Bus::dispatchSync(new FetchVideoTranscript($thought->id, false));

        $thought->refresh();
        $this->assertSame('idea', $thought->metadata['type']);
    }

    public function test_non_root_thought_exits_without_fetch(): void
    {
        $user = User::factory()->create();
        $parent = $this->createVideoRoot($user);
        $child = Thought::query()->create([
            'content' => '## Transcript',
            'embedding' => null,
            'metadata' => [
                'video_section_type' => 'transcript',
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => $parent->id,
        ]);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldNotReceive('fetchForUrl');
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('embed');
        $this->app->instance(OpenRouterService::class, $openRouter);

        Bus::dispatchSync(new FetchVideoTranscript($child->id, false));

        $child->refresh();
        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_manual_transcript_does_not_call_youtube_or_change_root(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'transcript_status' => 'manual',
            'transcript_source' => 'pasted',
        ]);
        $originalContent = $root->content;

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldNotReceive('fetchForUrl');
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('embed');
        $this->app->instance(OpenRouterService::class, $openRouter);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertSame($originalContent, $root->content);
        $this->assertSame('manual', $root->metadata['transcript_status']);
        $this->assertSame('pasted', $root->metadata['transcript_source']);
    }

    public function test_manual_status_alone_causes_noop(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'transcript_status' => 'manual',
            'transcript_source' => 'none',
        ]);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldNotReceive('fetchForUrl');
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('embed');
        $this->app->instance(OpenRouterService::class, $openRouter);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertSame('manual', $root->metadata['transcript_status']);
        $this->assertSame('none', $root->metadata['transcript_source']);
    }

    public function test_pasted_source_alone_causes_noop(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'transcript_status' => 'pending',
            'transcript_source' => 'pasted',
        ]);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldNotReceive('fetchForUrl');
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('embed');
        $this->app->instance(OpenRouterService::class, $openRouter);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertSame('pending', $root->metadata['transcript_status']);
        $this->assertSame('pasted', $root->metadata['transcript_source']);
    }

    public function test_available_root_does_not_fetch_again(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'transcript_status' => 'available',
            'transcript_source' => 'youtube',
        ]);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldNotReceive('fetchForUrl');
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('embed');
        $this->app->instance(OpenRouterService::class, $openRouter);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertSame('available', $root->metadata['transcript_status']);
    }

    public function test_unavailable_root_does_not_fetch_again(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'transcript_status' => 'unavailable',
            'transcript_source' => 'none',
        ]);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldNotReceive('fetchForUrl');
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('embed');
        $this->app->instance(OpenRouterService::class, $openRouter);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertSame('unavailable', $root->metadata['transcript_status']);
    }

    public function test_failed_root_does_not_fetch_again(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'transcript_status' => 'failed',
            'transcript_source' => 'none',
            'transcript_error_reason' => 'youtube_fetch_failed',
        ]);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldNotReceive('fetchForUrl');
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('embed');
        $this->app->instance(OpenRouterService::class, $openRouter);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertSame('failed', $root->metadata['transcript_status']);
        $this->assertSame('youtube_fetch_failed', $root->metadata['transcript_error_reason']);
    }

    public function test_research_pending_stays_true_when_research_now_and_fetch_fails(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'research_pending' => true,
        ]);

        $this->bindOpenRouterForEmbeds(1);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => false,
            'reason' => 'youtube_fetch_failed',
            'video_id' => 'abc12345678',
        ]);
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $this->runFetchVideoTranscriptJob($root->id, true);

        $root->refresh();
        $this->assertTrue($root->metadata['research_pending']);
        $this->assertTrue($root->metadata[VideoCaptureService::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH]);
    }

    public function test_ready_for_research_marker_is_set_for_unavailable_when_research_now_is_true(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'research_pending' => true,
        ]);

        $this->bindOpenRouterForEmbeds(1);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => false,
            'reason' => 'transcript_unavailable',
            'video_id' => 'abc12345678',
        ]);
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $this->runFetchVideoTranscriptJob($root->id, true);

        $root->refresh();
        $this->assertSame('unavailable', $root->metadata['transcript_status']);
        $this->assertTrue($root->metadata['research_pending']);
        $this->assertTrue($root->metadata[VideoCaptureService::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH]);
    }

    public function test_video_transcript_ready_for_research_marker_on_success_with_research_now(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'research_pending' => true,
        ]);

        $this->bindOpenRouterForEmbeds(2);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => true,
            'video_id' => 'abc12345678',
            'language_code' => 'en',
            'transcript' => 'Done.',
        ]);
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $this->runFetchVideoTranscriptJob($root->id, true);

        $root->refresh();
        $this->assertTrue($root->metadata['research_pending']);
        $this->assertTrue($root->metadata[VideoCaptureService::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH]);
    }

    public function test_empty_success_is_treated_as_unavailable_without_creating_transcript_child(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'transcript_error_reason' => 'stale',
        ]);

        $this->bindOpenRouterForEmbeds(1);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => true,
            'video_id' => 'abc12345678',
            'language_code' => 'en',
            'transcript' => '   ',
        ]);
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertSame('unavailable', $root->metadata['transcript_status']);
        $this->assertSame('none', $root->metadata['transcript_source']);
        $this->assertArrayNotHasKey('transcript_error_reason', $root->metadata);
        $this->assertSame(0, Thought::query()->where('parent_id', $root->id)->count());
    }

    public function test_empty_success_sets_ready_for_research_marker_when_research_now_is_true(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user, [
            'research_pending' => true,
        ]);

        $this->bindOpenRouterForEmbeds(1);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => true,
            'video_id' => 'abc12345678',
            'language_code' => 'en',
            'transcript' => '   ',
        ]);
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $this->runFetchVideoTranscriptJob($root->id, true);

        $root->refresh();
        $this->assertSame('unavailable', $root->metadata['transcript_status']);
        $this->assertTrue($root->metadata['research_pending']);
        $this->assertTrue($root->metadata[VideoCaptureService::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH]);
    }

    public function test_missing_user_on_success_marks_root_failed(): void
    {
        $user = User::factory()->create();
        $root = $this->createVideoRoot($user);
        $user->delete();

        $this->bindOpenRouterForEmbeds(1);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => true,
            'video_id' => 'abc12345678',
            'language_code' => 'en',
            'transcript' => 'This will not be stored.',
        ]);
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        Bus::dispatchSync(new FetchVideoTranscript($root->id, false));

        $root->refresh();
        $this->assertNull($root->user_id);
        $this->assertSame('failed', $root->metadata['transcript_status']);
        $this->assertSame('missing_user', $root->metadata['transcript_error_reason']);
        $this->assertSame(0, Thought::query()->where('parent_id', $root->id)->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
