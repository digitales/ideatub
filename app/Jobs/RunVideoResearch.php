<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\Video\VideoResearchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunVideoResearch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $videoThoughtId,
    ) {}

    public function uniqueId(): string
    {
        return 'video-research:'.$this->videoThoughtId;
    }

    public function handle(VideoResearchService $videoResearch): void
    {
        $root = Thought::query()->find($this->videoThoughtId);
        if ($root === null) {
            Log::warning('RunVideoResearch: video root not found.', [
                'thought_id' => $this->videoThoughtId,
            ]);

            return;
        }

        if ($root->parent_id !== null || data_get($root->metadata, 'type') !== 'video') {
            return;
        }

        // Let failures bubble so the queue marks the job failed; the service already clears
        // terminal metadata markers before rethrowing.
        $videoResearch->runAndSaveForVideoRoot($root);
    }
}
