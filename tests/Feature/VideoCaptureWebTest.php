<?php

namespace Tests\Feature;

use App\Jobs\FetchVideoTranscript;
use App\Jobs\RunResearchRun;
use App\Jobs\RunVideoResearch;
use App\Models\Thought;
use App\Models\User;
use App\Services\Email\EmailLinkExtractor;
use App\Services\OpenRouterService;
use App\Services\Video\VideoCaptureService;
use App\Services\Video\VideoThoughtContentBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VideoCaptureWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_post_youtube_url_creates_video_thought(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $embed = array_fill(0, 1536, 0.03);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
        });

        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $response = $this->actingAs($user)->post(route('videos.store'), [
            'youtube_url' => $url,
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('idea.ideas'));
        $response->assertSessionHas('success', 'Video saved.');

        $video = Thought::query()
            ->where('user_id', $user->id)
            ->whereNull('parent_id')
            ->where('metadata->type', 'video')
            ->first();
        $this->assertNotNull($video);
        $this->assertSame('dQw4w9WgXcQ', $video->metadata['video_id']);
        $this->assertSame('video', $video->source);
        $this->assertStringContainsString($url, (string) $video->content);
    }

    public function test_post_youtube_url_json_returns_message_and_home_redirect(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $embed = array_fill(0, 1536, 0.03);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
        });

        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $response = $this->actingAs($user)->postJson(route('videos.store'), [
            'youtube_url' => $url,
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Video saved.');
        $response->assertJsonPath('redirect', route('idea.index'));

        $this->assertNotNull(Thought::query()
            ->where('user_id', $user->id)
            ->whereNull('parent_id')
            ->where('metadata->type', 'video')
            ->first());
    }

    public function test_post_without_transcript_queues_fetch_video_transcript(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $embed = array_fill(0, 1536, 0.03);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
        });

        $this->actingAs($user)->post(route('videos.store'), [
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
            '_token' => csrf_token(),
        ]);

        $video = Thought::query()
            ->where('user_id', $user->id)
            ->where('metadata->type', 'video')
            ->sole();

        Queue::assertPushed(FetchVideoTranscript::class, function (FetchVideoTranscript $job) use ($video): bool {
            return $job->videoThoughtId === $video->id && $job->researchNow === false;
        });
    }

    public function test_post_with_transcript_does_not_queue_fetch_video_transcript(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $embed = array_fill(0, 1536, 0.03);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->twice()->andReturn($embed);
        });

        $this->actingAs($user)->post(route('videos.store'), [
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'transcript' => "First line.\nSecond line.",
            '_token' => csrf_token(),
        ]);

        Queue::assertNothingPushed();
    }

    public function test_post_with_transcript_and_return_thought_id_redirects_to_thought_show(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $embed = array_fill(0, 1536, 0.03);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->twice()->andReturn($embed);
        });

        $canonical = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $video = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'embedding' => $embed,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'dQw4w9WgXcQ',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_UNAVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_NONE,
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($user)->post(route('videos.store'), [
            'youtube_url' => $canonical,
            'transcript' => "Line one.\nLine two.",
            'return_thought_id' => $video->id,
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('thoughts.show', $video));
        $response->assertSessionHas('success', 'Transcript saved.');

        $transcriptChild = Thought::query()
            ->where('parent_id', $video->id)
            ->where('metadata->video_section_type', 'transcript')
            ->sole();
        $this->assertStringContainsString('Line one.', (string) $transcriptChild->content);
        Queue::assertNothingPushed();
    }

    public function test_repeated_post_without_transcript_only_queues_one_fetch_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $embed = array_fill(0, 1536, 0.03);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->twice()->andReturn($embed);
        });

        $payload = [
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
            '_token' => csrf_token(),
        ];

        $this->actingAs($user)->post(route('videos.store'), $payload);
        $this->actingAs($user)->post(route('videos.store'), $payload);

        $video = Thought::query()
            ->where('user_id', $user->id)
            ->where('metadata->type', 'video')
            ->sole();

        Queue::assertPushed(FetchVideoTranscript::class, function (FetchVideoTranscript $job) use ($video): bool {
            return $job->videoThoughtId === $video->id && $job->researchNow === false;
        });
        Queue::assertPushed(FetchVideoTranscript::class, 1);
    }

    public function test_post_invalid_youtube_url_returns_validation_error(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('idea.ideas'))
            ->post(route('videos.store'), [
                'youtube_url' => 'https://example.com/not-youtube',
                '_token' => csrf_token(),
            ]);

        $response->assertRedirect(route('idea.ideas'));
        $response->assertSessionHasErrors('youtube_url');
        $this->assertSame(0, Thought::query()->where('user_id', $user->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_post_youtu_be_url_with_trailing_path_prose_returns_validation_error(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('idea.ideas'))
            ->post(route('videos.store'), [
                'youtube_url' => 'https://youtu.be/dQw4w9WgXcQNotes',
                '_token' => csrf_token(),
            ]);

        $response->assertRedirect(route('idea.ideas'));
        $response->assertSessionHasErrors('youtube_url');
        $this->assertSame(0, Thought::query()->where('user_id', $user->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_post_research_now_preserves_intent_metadata_without_pushing_video_research_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $embed = array_fill(0, 1536, 0.03);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
        });

        $this->actingAs($user)->post(route('videos.store'), [
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'research_now' => '1',
            '_token' => csrf_token(),
        ]);

        $video = Thought::query()
            ->where('user_id', $user->id)
            ->where('metadata->type', 'video')
            ->sole();

        $this->assertTrue($video->metadata[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING] ?? false);
        Queue::assertPushed(FetchVideoTranscript::class, function (FetchVideoTranscript $job) use ($video): bool {
            return $job->videoThoughtId === $video->id && $job->researchNow === true;
        });
        Queue::assertNotPushed(RunResearchRun::class);
        Queue::assertNotPushed(RunVideoResearch::class);
    }

    public function test_post_research_now_clears_pending_markers_when_fetch_cannot_be_queued(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $embed = array_fill(0, 1536, 0.03);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
        });
        $this->app->instance(VideoCaptureService::class, new class(app(EmailLinkExtractor::class), app(OpenRouterService::class), app(VideoThoughtContentBuilder::class)) extends VideoCaptureService
        {
            public function queueTranscriptFetchIfPending(Thought $root, bool $researchNow = false): bool
            {
                return false;
            }
        });

        $response = $this->actingAs($user)->post(route('videos.store'), [
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'research_now' => '1',
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('idea.ideas'));
        $response->assertSessionHas('warning', 'Transcript fetch could not be queued; the video was saved. Retry transcript fetch later if needed.');

        $video = Thought::query()
            ->where('user_id', $user->id)
            ->where('metadata->type', 'video')
            ->sole();

        $this->assertFalse((bool) ($video->metadata['research_pending'] ?? false));
        $this->assertFalse((bool) ($video->metadata[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING] ?? false));
        Queue::assertNotPushed(FetchVideoTranscript::class);
        Queue::assertNotPushed(RunVideoResearch::class);
    }

    public function test_guest_cannot_post_videos_store(): void
    {
        $response = $this->post(route('videos.store'), [
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('login'));
    }
}
