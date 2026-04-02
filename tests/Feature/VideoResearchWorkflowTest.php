<?php

namespace Tests\Feature;

use App\Jobs\FetchVideoTranscript;
use App\Jobs\RunVideoResearch;
use App\Models\Thought;
use App\Models\User;
use App\Services\Email\YouTubeTranscriptService;
use App\Services\OpenRouterService;
use App\Services\Video\VideoCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VideoResearchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_research_now_with_pending_transcript_queues_fetch_only_not_video_research(): void
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

        $this->assertTrue($video->metadata['research_pending'] ?? false);
        Queue::assertPushed(FetchVideoTranscript::class, function (FetchVideoTranscript $job) use ($video): bool {
            return $job->videoThoughtId === $video->id && $job->researchNow === true;
        });
        Queue::assertNotPushed(RunVideoResearch::class);
    }

    public function test_terminal_transcript_fetch_with_research_intent_queues_run_video_research_once(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $root = Thought::query()->create([
            'content' => 'v',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc12345678',
                'video_url' => 'https://www.youtube.com/watch?v=abc12345678',
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_PENDING,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_NONE,
                'research_pending' => true,
                VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING => true,
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
        ]);

        Queue::assertNothingPushed();

        $embed = array_fill(0, 1536, 0.02);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
        });

        $youtube = \Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => false,
            'reason' => 'transcript_unavailable',
            'video_id' => 'abc12345678',
        ]);
        $this->app->instance(YouTubeTranscriptService::class, $youtube);

        $fetchJob = new FetchVideoTranscript($root->id, true);
        $this->app->call([$fetchJob, 'handle']);

        Queue::assertPushed(RunVideoResearch::class, function (RunVideoResearch $job) use ($root): bool {
            return $job->videoThoughtId === $root->id;
        });
        Queue::assertPushed(RunVideoResearch::class, 1);
    }

    public function test_manual_transcript_and_research_now_queues_run_video_research_immediately(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $embed = array_fill(0, 1536, 0.03);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->twice()->andReturn($embed);
        });

        $this->actingAs($user)->post(route('videos.store'), [
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'transcript' => "Line one.\nLine two.",
            'research_now' => '1',
            '_token' => csrf_token(),
        ]);

        $video = Thought::query()
            ->where('user_id', $user->id)
            ->where('metadata->type', 'video')
            ->sole();

        $this->assertTrue($video->metadata['research_pending'] ?? false);
        Queue::assertNotPushed(FetchVideoTranscript::class);
        Queue::assertPushed(RunVideoResearch::class, function (RunVideoResearch $job) use ($video): bool {
            return $job->videoThoughtId === $video->id;
        });
        Queue::assertPushed(RunVideoResearch::class, 1);
    }

    public function test_research_thought_id_updates_only_after_successful_research_save(): void
    {
        $user = User::factory()->create();
        $root = Thought::query()->create([
            'content' => 'v',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'x1',
                'video_url' => 'https://www.youtube.com/watch?v=x1',
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'research_pending' => true,
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
        ]);
        Thought::query()->create([
            'content' => "## Transcript\n\nbody",
            'embedding' => null,
            'metadata' => ['video_section_type' => 'transcript'],
            'user_id' => $user->id,
            'source' => 'video',
            'parent_id' => $root->id,
        ]);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('researchFromPrompt')->once()->andThrow(new \RuntimeException('fail'));
        });

        $runJob = new RunVideoResearch($root->id);
        try {
            $this->app->call([$runJob, 'handle']);
            $this->fail('Expected fail exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('fail', $e->getMessage());
        }

        $root->refresh();
        $this->assertNull(data_get($root->metadata, 'research_thought_id'));
        $this->assertFalse((bool) ($root->metadata['research_pending'] ?? false));
    }

    public function test_run_video_research_rethrows_failures_after_cleanup(): void
    {
        $user = User::factory()->create();
        $root = Thought::query()->create([
            'content' => 'v',
            'embedding' => null,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'x2',
                'video_url' => 'https://www.youtube.com/watch?v=x2',
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'research_pending' => true,
            ],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
        ]);
        Thought::query()->create([
            'content' => "## Transcript\n\nbody",
            'embedding' => null,
            'metadata' => ['video_section_type' => 'transcript'],
            'user_id' => $user->id,
            'source' => 'video',
            'parent_id' => $root->id,
        ]);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('researchFromPrompt')->once()->andThrow(new \RuntimeException('queue-visible-fail'));
        });

        $runJob = new RunVideoResearch($root->id);

        try {
            $this->app->call([$runJob, 'handle']);
            $this->fail('Expected queue-visible-fail exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('queue-visible-fail', $e->getMessage());
        }

        $root->refresh();
        $this->assertFalse((bool) ($root->metadata['research_pending'] ?? false));
        $this->assertNull(data_get($root->metadata, 'research_thought_id'));
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
