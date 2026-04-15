<?php

namespace App\Services\Meetings;

use App\Models\MeetingRun;
use App\Models\Thought;
use App\Services\OpenRouterService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

class MeetingWorkflowRunner
{
    private const ERROR_SUMMARY_MAX_CHARS = 2000;

    public function __construct(
        private MeetingPromptBuilder $promptBuilder,
        private OpenRouterService $openRouter,
        private MeetingService $meetingService,
    ) {}

    public function run(MeetingRun $run): void
    {
        $run->load(['meetingThought', 'meetingSkillVersion']);

        if ($run->status === 'cancelled') {
            return;
        }

        if ($run->workflow_type_snapshot !== MeetingSkillManager::WORKFLOW_MEETING_BRIEF) {
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
            $meeting = $run->meetingThought;

            $version = $run->meetingSkillVersion;
            $outputShape = $run->output_shape_snapshot ?? $version->output_shape ?? [];
            $coreCategories = $run->core_categories_snapshot ?? $version->core_categories ?? MeetingSkillManager::DEFAULT_CORE_CATEGORIES;
            $customCategories = $run->custom_categories_snapshot ?? $version->custom_categories ?? [];
            $intensity = $run->intensity_snapshot ?? $version->intensity ?? 'standard';
            $instructions = (string) ($version->instructions ?? '');

            if (! is_array($outputShape)) {
                $outputShape = [];
            }

            $transcript = $this->resolveTranscriptText($meeting, $flags);

            $prompt = $this->promptBuilder->buildMeetingBriefPrompt(
                meeting: $meeting,
                instructions: $instructions,
                transcriptText: $transcript,
                intensity: $intensity,
                coreCategories: is_array($coreCategories) ? $coreCategories : MeetingSkillManager::DEFAULT_CORE_CATEGORIES,
                customCategories: is_array($customCategories) ? $customCategories : [],
                outputShape: $outputShape,
                relatedThoughts: $relatedThoughts,
            );

            $result = $this->openRouter->researchFromPrompt($prompt);
            $normalized = $this->normalizeResultPayload($result, is_array($coreCategories) ? $coreCategories : MeetingSkillManager::DEFAULT_CORE_CATEGORIES);
            $thought = $this->meetingService->saveRunResult($run, $result, $normalized);

            $run->update([
                'status' => 'completed',
                'final_meeting_thought_id' => $thought->id,
                'error_summary' => null,
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'current_stage' => 0,
                'final_meeting_thought_id' => null,
                'error_summary' => $this->truncateErrorSummary($e->getMessage()),
            ]);
        }
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function parseContextSnapshot(MeetingRun $run): array
    {
        $raw = $run->context_options_snapshot ?? $run->meetingSkillVersion->context_options;

        if ($raw === null || $raw === []) {
            return [['meeting_content', 'transcript'], []];
        }

        if ($this->isStringList($raw)) {
            return [array_values(array_unique($raw)), []];
        }

        if (! is_array($raw)) {
            return [['meeting_content', 'transcript'], []];
        }

        $includes = $raw['includes'] ?? null;
        if (is_array($includes) && $includes !== [] && $this->isStringList($includes)) {
            $flags = array_values(array_unique($includes));
        } else {
            $flags = ['meeting_content', 'transcript'];
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
    private function resolveRelatedThoughts(MeetingRun $run, array $orderedIds): Collection
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
        foreach (array_slice($orderedIds, 0, MeetingPromptBuilder::MAX_CONTEXT_THOUGHTS) as $id) {
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
    private function resolveTranscriptText(Thought $meeting, array $flags): string
    {
        $content = trim($meeting->getDecodedContent());

        if (! in_array('transcript', $flags, true)) {
            return $content;
        }

        $transcript = data_get($meeting->source_metadata, 'transcript');
        if (is_string($transcript) && trim($transcript) !== '') {
            return trim($transcript);
        }

        return $content;
    }

    /**
     * @param  array<int, string>  $coreCategories
     * @return array<string, mixed>
     */
    private function normalizeResultPayload(string $result, array $coreCategories): array
    {
        $decoded = $this->decodeJsonObject($result);
        $defaultCore = $this->defaultCoreCategoryShape($coreCategories);

        if (! is_array($decoded)) {
            return [
                'summary' => trim($result),
                'core_categories' => $defaultCore,
                'custom_sections' => [],
                'requested_sections' => [],
            ];
        }

        $summary = isset($decoded['summary']) && is_string($decoded['summary'])
            ? trim($decoded['summary'])
            : trim($result);

        $coreRaw = is_array($decoded['core_categories'] ?? null) ? $decoded['core_categories'] : [];
        $core = $defaultCore;
        foreach ($core as $key => $defaultValue) {
            if (! array_key_exists($key, $coreRaw)) {
                continue;
            }

            if ($key === 'action_items') {
                $core[$key] = $this->normalizeActionItems($coreRaw[$key]);

                continue;
            }

            $core[$key] = $this->normalizeStringArray($coreRaw[$key]);
        }

        $customSections = [];
        $rawCustom = $decoded['custom_sections'] ?? [];
        if (is_array($rawCustom)) {
            foreach ($rawCustom as $section => $values) {
                if (! is_string($section) || trim($section) === '') {
                    continue;
                }
                $customSections[trim($section)] = $this->normalizeStringArray($values);
            }
        }

        $requestedSections = [];
        $rawRequested = $decoded['requested_sections'] ?? [];
        if (is_array($rawRequested)) {
            foreach ($rawRequested as $section => $value) {
                if (! is_string($section) || trim($section) === '') {
                    continue;
                }

                $requestedSections[trim($section)] = is_string($value)
                    ? trim($value)
                    : json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        return [
            'summary' => $summary,
            'core_categories' => $core,
            'custom_sections' => $customSections,
            'requested_sections' => $requestedSections,
        ];
    }

    /**
     * @param  array<int, string>  $coreCategories
     * @return array<string, mixed>
     */
    private function defaultCoreCategoryShape(array $coreCategories): array
    {
        $shape = [];
        foreach ($coreCategories as $category) {
            if (! is_string($category) || trim($category) === '') {
                continue;
            }

            $key = trim($category);
            $shape[$key] = $key === 'action_items' ? [] : [];
        }

        foreach (MeetingSkillManager::DEFAULT_CORE_CATEGORIES as $defaultCategory) {
            if (! array_key_exists($defaultCategory, $shape)) {
                $shape[$defaultCategory] = [];
            }
        }

        return $shape;
    }

    /**
     * @return array<int, array{task: string, owner: ?string, due_date: ?string, confidence: string}>
     */
    private function normalizeActionItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                if (is_string($row) && trim($row) !== '') {
                    $items[] = [
                        'task' => trim($row),
                        'owner' => null,
                        'due_date' => null,
                        'confidence' => 'medium',
                    ];
                }

                continue;
            }

            $task = trim((string) ($row['task'] ?? ''));
            if ($task === '') {
                continue;
            }

            $confidence = trim((string) ($row['confidence'] ?? 'medium'));
            if (! in_array($confidence, ['high', 'medium', 'low'], true)) {
                $confidence = 'medium';
            }

            $owner = isset($row['owner']) && is_string($row['owner']) && trim($row['owner']) !== ''
                ? trim($row['owner'])
                : null;
            $dueDate = isset($row['due_date']) && is_string($row['due_date']) && trim($row['due_date']) !== ''
                ? trim($row['due_date'])
                : null;

            $items[] = [
                'task' => $task,
                'owner' => $owner,
                'due_date' => $dueDate,
                'confidence' => $confidence,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $normalized = trim($item);
            if ($normalized === '') {
                continue;
            }
            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function decodeJsonObject(string $result): ?array
    {
        $content = trim($result);
        if ($content === '') {
            return null;
        }

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $content) ?? $content;
            $content = trim($content);
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
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
