<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\Email\YouTubeTranscriptService;
use App\Services\Video\VideoCaptureService;
use App\Services\Video\VideoResearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchVideoTranscript implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Task 2 uses a single best-effort background attempt; later tasks can explicitly reset and re-dispatch.
     */
    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $videoThoughtId,
        public readonly bool $researchNow = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->videoThoughtId;
    }

    public function handle(
        VideoCaptureService $videoCapture,
        YouTubeTranscriptService $youtube,
        VideoResearchService $videoResearch,
    ): void {
        $root = Thought::query()->find($this->videoThoughtId);
        if ($root === null) {
            Log::warning('FetchVideoTranscript: thought not found.', [
                'thought_id' => $this->videoThoughtId,
            ]);

            return;
        }

        if ($root->parent_id !== null) {
            Log::warning('FetchVideoTranscript: expected root thought.', [
                'thought_id' => $this->videoThoughtId,
            ]);

            return;
        }

        if (data_get($root->metadata, 'type') !== 'video') {
            return;
        }

        if ($videoCapture->transcriptFetchShouldNoop($root)) {
            return;
        }

        $url = $this->transcriptFetchUrl($root);
        if (! is_string($url) || $url === '') {
            $applyState = (object) ['applied' => false];
            DB::transaction(function () use ($videoCapture, $applyState): void {
                $locked = Thought::query()->whereKey($this->videoThoughtId)->lockForUpdate()->first();
                if ($locked === null || $videoCapture->transcriptFetchShouldNoop($locked)) {
                    return;
                }
                $videoCapture->applyTranscriptFetchResult($locked, [
                    'ok' => false,
                    'reason' => 'missing_video_reference',
                    'video_id' => data_get($locked->metadata, 'video_id'),
                ], $this->researchNow);
                $applyState->applied = true;
            });
            if ($applyState->applied) {
                $videoResearch->queueRunAfterTranscriptTerminalIfEligible($this->videoThoughtId, $this->researchNow);
            }

            return;
        }

        $result = $youtube->fetchForUrl($url);

        $applyState = (object) ['applied' => false];
        DB::transaction(function () use ($videoCapture, $result, $applyState): void {
            $locked = Thought::query()->whereKey($this->videoThoughtId)->lockForUpdate()->first();
            if ($locked === null || $videoCapture->transcriptFetchShouldNoop($locked)) {
                return;
            }
            $videoCapture->applyTranscriptFetchResult($locked, $result, $this->researchNow);
            $applyState->applied = true;
        });
        if ($applyState->applied) {
            $videoResearch->queueRunAfterTranscriptTerminalIfEligible($this->videoThoughtId, $this->researchNow);
        }
    }

    private function transcriptFetchUrl(Thought $root): ?string
    {
        $videoId = data_get($root->metadata, 'video_id');
        if (is_string($videoId) && $videoId !== '') {
            return 'https://www.youtube.com/watch?v='.$videoId;
        }

        $videoUrl = data_get($root->metadata, 'video_url');

        return is_string($videoUrl) && $videoUrl !== '' ? $videoUrl : null;
    }
}
