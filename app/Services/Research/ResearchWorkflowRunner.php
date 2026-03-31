<?php

namespace App\Services\Research;

use App\Models\ResearchRun;
use App\Models\Thought;
use App\Services\OpenRouterService;
use App\Services\ResearchService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

class ResearchWorkflowRunner
{
    private const ERROR_SUMMARY_MAX_CHARS = 2000;

    public function __construct(
        private ResearchPromptBuilder $promptBuilder,
        private OpenRouterService $openRouter,
        private ResearchService $researchService,
    ) {}

    public function run(ResearchRun $run): void
    {
        $run->load(['ideaThought', 'researchSkillVersion']);

        if ($run->workflow_type_snapshot !== 'quick_brief') {
            throw new InvalidArgumentException('Unsupported workflow type: '.$run->workflow_type_snapshot);
        }

        $run->update([
            'status' => 'running',
            'current_stage' => 1,
            'error_summary' => null,
        ]);

        try {
            [$flags, $relatedIds] = $this->parseContextSnapshot($run);
            $relatedThoughts = $this->resolveRelatedThoughts($run, $relatedIds);
            $existingResearch = $this->resolveExistingResearchContent($run, $flags);

            $version = $run->researchSkillVersion;
            $outputShape = $run->output_shape_snapshot ?? $version->output_shape ?? [];
            $intensity = $run->intensity_snapshot ?? $version->intensity ?? 'standard';
            $instructions = (string) ($version->instructions ?? '');

            if (! is_array($outputShape)) {
                $outputShape = [];
            }

            $prompt = $this->promptBuilder->buildQuickBriefPrompt(
                idea: $run->ideaThought,
                instructions: $instructions,
                contextOptions: $flags,
                outputShape: $outputShape,
                intensity: $intensity,
                relatedThoughts: $relatedThoughts,
                existingResearchContent: $existingResearch,
            );

            $result = $this->openRouter->researchFromPrompt($prompt);
            $thought = $this->researchService->saveRunResult($run, $result);

            $run->update([
                'status' => 'completed',
                'final_research_thought_id' => $thought->id,
                'error_summary' => null,
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error_summary' => $this->truncateErrorSummary($e->getMessage()),
            ]);
        }
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function parseContextSnapshot(ResearchRun $run): array
    {
        $raw = $run->context_options_snapshot ?? $run->researchSkillVersion->context_options;

        if ($raw === null || $raw === []) {
            return [['idea', 'tags', 'existing_research'], []];
        }

        if ($this->isStringList($raw)) {
            return [array_values(array_unique($raw)), []];
        }

        if (! is_array($raw)) {
            return [['idea', 'tags', 'existing_research'], []];
        }

        $includes = $raw['includes'] ?? null;
        if (is_array($includes) && $includes !== [] && $this->isStringList($includes)) {
            $flags = array_values(array_unique($includes));
        } else {
            $flags = ['idea', 'tags', 'existing_research'];
        }

        $ids = $raw['related_thought_ids'] ?? [];
        if (! is_array($ids)) {
            $ids = [];
        }

        $stringIds = [];
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $stringIds[] = $id;
            }
        }

        return [$flags, $stringIds];
    }

    /**
     * @param  array<mixed>  $arr
     */
    private function isStringList(array $arr): bool
    {
        if ($arr === [] || ! array_is_list($arr)) {
            return false;
        }

        foreach ($arr as $v) {
            if (! is_string($v)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $orderedIds
     */
    private function resolveRelatedThoughts(ResearchRun $run, array $orderedIds): Collection
    {
        if ($orderedIds === []) {
            return collect();
        }

        $ids = array_slice($orderedIds, 0, 50);
        $thoughts = Thought::query()
            ->where('user_id', $run->user_id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = collect();
        foreach (array_slice($orderedIds, 0, ResearchPromptBuilder::MAX_RELATED_THOUGHTS) as $id) {
            $thought = $thoughts->get($id);
            if ($thought instanceof Thought) {
                $ordered->push($thought);
            }
        }

        return $ordered;
    }

    /**
     * @param  array<int, string>  $flags
     */
    private function resolveExistingResearchContent(ResearchRun $run, array $flags): ?string
    {
        if (! in_array('existing_research', $flags, true)) {
            return null;
        }

        $latest = Thought::researchForIdea($run->idea_thought_id)
            ->where('user_id', $run->user_id)
            ->orderByDesc('created_at')
            ->first();

        if ($latest === null) {
            return null;
        }

        return $latest->getDecodedContent();
    }

    private function truncateErrorSummary(string $message): string
    {
        $message = trim($message);
        if (mb_strlen($message) <= self::ERROR_SUMMARY_MAX_CHARS) {
            return $message;
        }

        return rtrim(mb_substr($message, 0, self::ERROR_SUMMARY_MAX_CHARS - 1)).'…';
    }
}
