<?php

namespace App\Services;

use App\Models\Thought;

/**
 * Runs research for ideas and creates linked research thoughts.
 * Research thoughts are created directly (no embedding) and linked via metadata.idea_id.
 */
class ResearchService
{
    public function __construct(
        private OpenRouterService $openRouter,
        private ThoughtCaptureService $captureService
    ) {}

    /**
     * Run research for an existing idea and create a linked research thought.
     *
     * @param  string  $source  'web' or 'mcp'
     * @throws \Illuminate\Http\Client\RequestException
     * @throws \RuntimeException
     */
    public function runResearchForIdea(Thought $idea, string $source = 'web'): Thought
    {
        $researchText = $this->openRouter->researchNote($idea->content);

        return Thought::create([
            'content' => $researchText,
            'embedding' => null,
            'metadata' => [
                'type' => 'research',
                'idea_id' => $idea->id,
            ],
            'user_id' => $idea->user_id,
            'source' => $source,
        ]);
    }

    /**
     * Create an idea thought then run research and link the research thought.
     * If research fails, the idea is still created; research will be null.
     *
     * @param  string  $source  'web' or 'mcp'
     * @return array{idea: Thought, research: Thought|null}
     */
    public function createIdeaAndResearch(string $ideaContent, string $source = 'web'): array
    {
        $ideaMetadata = [
            'type' => 'idea',
            'completed' => false,
            'logged_date' => now()->toDateString(),
        ];

        $result = $this->captureService->create([
            'content' => trim($ideaContent),
            'user_id' => (int) auth()->id(),
            'source' => $source,
            'idea_metadata' => $ideaMetadata,
        ]);

        $idea = $result['thought'] ?? $result['root'];
        if (! $idea instanceof Thought) {
            throw new \RuntimeException('ThoughtCaptureService did not return an idea thought.');
        }

        try {
            $research = $this->runResearchForIdea($idea, $source);
        } catch (\Throwable) {
            return ['idea' => $idea, 'research' => null];
        }

        return ['idea' => $idea, 'research' => $research];
    }
}
