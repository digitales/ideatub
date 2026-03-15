<?php

namespace App\Listeners;

use App\Events\IdeaResearchRequested;
use App\Models\Thought;
use App\Services\ResearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class RunResearchForIdeaListener implements ShouldQueue
{
    /**
     * Run research in the background. On success or failure, clear research_pending on the idea.
     */
    public function handle(IdeaResearchRequested $event): void
    {
        $idea = $event->idea;

        try {
            app(ResearchService::class)->runResearchForIdea($idea, $event->source);
        } catch (\Throwable $e) {
            Log::warning('RunResearchForIdea: research failed', [
                'idea_id' => $idea->id,
                'message' => $e->getMessage(),
            ]);
            report($e);
        } finally {
            $this->clearResearchPending($idea->id);
        }
    }

    private function clearResearchPending(string $ideaId): void
    {
        $thought = Thought::find($ideaId);
        if ($thought === null) {
            return;
        }

        $metadata = $thought->metadata ?? [];
        unset($metadata['research_pending']);
        $thought->update(['metadata' => $metadata]);
    }
}
