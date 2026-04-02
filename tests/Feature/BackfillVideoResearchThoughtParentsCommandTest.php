<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\Video\VideoCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillVideoResearchThoughtParentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write(): void
    {
        $user = User::factory()->create();
        $video = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'video',
            'metadata' => [
                'type' => 'video',
                'video_id' => 'dryRunVid',
                'video_url' => 'https://www.youtube.com/watch?v=dryRunVid',
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
            ],
        ]);
        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'research',
            'content' => 'Research body',
            'metadata' => [
                'type' => 'research',
                'tags' => ['research', 'video'],
                'video_thought_id' => $video->id,
            ],
            'source_metadata' => [
                'video_thought_id' => $video->id,
                'video_id' => 'dryRunVid',
                'transcript_context_available' => true,
            ],
        ]);

        $this->artisan('video-research:backfill-parents', ['--dry-run' => true])->assertSuccessful();

        $research->refresh();
        $this->assertNull($research->parent_id);
    }

    public function test_backfill_sets_parent_and_video_section_type(): void
    {
        $user = User::factory()->create();
        $video = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'video',
            'metadata' => [
                'type' => 'video',
                'video_id' => 'bfVid',
                'video_url' => 'https://www.youtube.com/watch?v=bfVid',
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'research_thought_id' => 'will-replace',
            ],
        ]);
        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'research',
            'content' => 'Research body unique bf-1',
            'metadata' => [
                'type' => 'research',
                'tags' => ['research', 'video'],
                'video_thought_id' => $video->id,
            ],
            'source_metadata' => [
                'video_thought_id' => $video->id,
                'video_id' => 'bfVid',
                'transcript_context_available' => true,
            ],
        ]);

        $this->artisan('video-research:backfill-parents')->assertSuccessful();

        $research->refresh();
        $this->assertSame($video->id, $research->parent_id);
        $this->assertSame('research', $research->metadata['video_section_type'] ?? null);
    }

    public function test_skips_when_video_root_missing(): void
    {
        $user = User::factory()->create();
        $missingId = '01900000-0000-7000-8000-000000000099';
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'research',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
                'video_thought_id' => $missingId,
            ],
        ]);

        $this->artisan('video-research:backfill-parents')->assertSuccessful();
    }
}
