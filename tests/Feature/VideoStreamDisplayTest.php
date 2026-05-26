<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\DemoMode;
use App\Services\Video\VideoCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoStreamDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_stream_video_card_surfaces_video_badge_transcript_state_and_canonical_link_without_comment_preview_for_transcript_child(): void
    {
        $user = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=streamVidTest01';

        $video = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'streamVidTest01',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_PENDING,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_NONE,
                'tags' => [],
            ],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $video->id,
            'source' => 'video',
            'content' => "## Transcript\n\nUNIQUE_TRANSCRIPT_PREVIEW_LEAK_STREAM_99",
            'metadata' => [
                'video_section_type' => 'transcript',
                'tags' => [],
            ],
        ]);

        $video->refresh()->load(['childThoughts' => fn ($q) => $q->orderBy('created_at')]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('data-thought-kind="video"', false);
        $response->assertSee('Fetching transcript', false);
        $response->assertSee($canonical, false);
        $response->assertDontSee('UNIQUE_TRANSCRIPT_PREVIEW_LEAK_STREAM_99', false);
        $response->assertDontSee('View formatted', false);
    }

    public function test_videos_stream_page_renders_video_cards_like_all_thoughts_stream(): void
    {
        $user = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=streamVidVideosTab01';

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'streamVidVideosTab01',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_PENDING,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_NONE,
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.videos'));

        $response->assertOk();
        $response->assertSee('Videos', false);
        $response->assertSee('data-thought-kind="video"', false);
        $response->assertSee('Fetching transcript', false);
        $response->assertSee($canonical, false);
    }

    public function test_stream_video_card_shows_view_research_when_latest_research_is_linked(): void
    {
        $user = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=streamVidResearch02';

        $video = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'streamVidResearch02',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'tags' => [],
            ],
        ]);

        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $video->id,
            'source' => 'research',
            'content' => '# Video research UNIQUE_STREAM_VID_RSRCH_CHILD_02',
            'metadata' => [
                'type' => 'research',
                'tags' => ['video'],
                'video_thought_id' => $video->id,
                'video_section_type' => 'research',
            ],
            'source_metadata' => [
                'video_thought_id' => $video->id,
                'video_id' => 'streamVidResearch02',
                'transcript_context_available' => true,
            ],
        ]);

        $video->update([
            'metadata' => array_merge($video->metadata ?? [], ['research_thought_id' => $research->id]),
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertDontSee('UNIQUE_STREAM_VID_RSRCH_CHILD_02', false);
        $response->assertSee('View research', false);
        $response->assertSee('Rerun research', false);
        $response->assertSee(route('videos.store'), false);
        $response->assertSee('name="youtube_url"', false);
        $response->assertSee('value="'.$canonical.'"', false);
        $response->assertSee('name="research_now"', false);
        $response->assertSee(route('idea.research.show', $research), false);
        $response->assertSee('Transcript available', false);
    }

    public function test_stream_video_card_shows_research_pending_when_video_root_is_research_pending(): void
    {
        $user = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=streamVidPending03';

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'streamVidPending03',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'research_pending' => true,
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('Research pending', false);
        $response->assertDontSee('Fetch transcript', false);
    }

    public function test_stream_video_card_shows_research_now_form_when_ready_without_linked_research(): void
    {
        $user = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=streamVidReady04';

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'streamVidReady04',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_MANUAL,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_PASTED,
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('Research now', false);
        $response->assertSee(route('videos.store'), false);
        $response->assertSee('name="youtube_url"', false);
        $response->assertSee('value="'.$canonical.'"', false);
        $response->assertSee('name="research_now"', false);
    }

    public function test_demo_mode_stream_video_card_does_not_expose_raw_canonical_url(): void
    {
        config(['services.demo_mode.enabled' => true]);

        $user = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=streamVidDemo05';

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'streamVidDemo05',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'tags' => [],
            ],
        ]);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'video-stream-demo-url',
        ])->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertDontSee($canonical, false);
        $response->assertDontSee('Open video', false);
    }

    public function test_stream_video_card_shows_fetch_transcript_form_when_transcript_is_missing_and_not_pending(): void
    {
        $user = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=streamVidFetch06';

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'streamVidFetch06',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_FAILED,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_NONE,
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('Fetch transcript', false);
        $response->assertSee(route('videos.store'), false);
        $response->assertSee('name="youtube_url"', false);
        $response->assertSee('value="'.$canonical.'"', false);
        $response->assertDontSee('name="research_now"', false);
    }
}
