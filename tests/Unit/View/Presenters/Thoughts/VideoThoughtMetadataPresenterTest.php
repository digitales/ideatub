<?php

namespace Tests\Unit\View\Presenters\Thoughts;

use App\Models\Thought;
use App\Models\User;
use App\Services\Video\VideoCaptureService;
use App\View\Presenters\Thoughts\VideoThoughtMetadataPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoThoughtMetadataPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_labeled_rows_include_core_fields_for_video_root(): void
    {
        $user = User::factory()->create();
        $video = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'video',
            'metadata' => [
                'type' => 'video',
                'video_id' => 'abc123xyz',
                'video_url' => 'https://www.youtube.com/watch?v=abc123xyz',
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
            ],
        ]);
        $video->setRelation('comments', collect());

        $rows = VideoThoughtMetadataPresenter::forVideoRoot($video)->labeledRows();

        $byLabel = collect($rows)->keyBy('label');
        $this->assertSame('abc123xyz', $byLabel->get('Video ID')['value']);
        $this->assertStringContainsString('youtube.com', $byLabel->get('URL')['value']);
        $this->assertSame('Transcript available', $byLabel->get('Transcript status')['value']);
        $this->assertSame('YouTube', $byLabel->get('Transcript source')['value']);
        $this->assertSame('Not stored yet', $byLabel->get('Transcript text')['value']);
        $this->assertSame('video', $byLabel->get('Captured as')['value']);
    }

    public function test_labeled_rows_empty_when_not_video_type(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);
        $thought->setRelation('comments', collect());

        $this->assertSame([], VideoThoughtMetadataPresenter::forVideoRoot($thought)->labeledRows());
    }
}
