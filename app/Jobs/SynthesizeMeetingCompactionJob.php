<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use App\Services\WorkingMemory\Compactions\MeetingCompactionPromptBuilder;
use App\Services\WorkingMemory\WorkingMemoryScopeResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SynthesizeMeetingCompactionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public string $thoughtId) {}

    public function handle(
        MeetingCompactionPromptBuilder $promptBuilder,
        OpenRouterService $openRouter,
        CompactionVersionWriter $writer,
        WorkingMemoryScopeResolver $scopeResolver,
    ): void {
        $meeting = Thought::query()->find($this->thoughtId);
        if ($meeting === null) {
            return;
        }

        $type = data_get($meeting->metadata, 'type');
        if ($type !== 'meeting') {
            return;
        }

        $userId = (int) $meeting->user_id;
        if ($userId <= 0) {
            return;
        }

        [$scopeType, $scopeKey] = $this->resolveScope($scopeResolver, $meeting);

        try {
            $prompt = $promptBuilder->build($meeting);
            $model = (string) config('working_memory.authoring_meeting_compaction_model', '');
            $temperature = config('working_memory.authoring_meeting_compaction_temperature');
            $raw = $openRouter->researchFromPrompt(
                $prompt,
                $model !== '' ? $model : null,
                is_numeric($temperature) ? (float) $temperature : null,
            );
            $decoded = $this->decodeJson($raw);

            if ($decoded === null) {
                Log::warning('SynthesizeMeetingCompactionJob: model returned non-JSON output.', [
                    'thought_id' => $meeting->id,
                ]);

                return;
            }

            $writer->write(
                userId: $userId,
                scopeType: $scopeType,
                scopeKey: $scopeKey,
                buildType: 'compaction:meeting',
                summaryMarkdown: (string) ($decoded['summary_markdown'] ?? ''),
                structuredSections: is_array($decoded['structured_sections'] ?? null) ? $decoded['structured_sections'] : [],
                references: is_array($decoded['references'] ?? null) ? $decoded['references'] : [],
                sourceThoughtIds: [$meeting->id],
            );
        } catch (Throwable $e) {
            Log::warning('SynthesizeMeetingCompactionJob failed.', [
                'thought_id' => $meeting->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Pick the most specific scope the refresh-side resolver will visit for this thought.
     *
     * Coherence requirement: a compaction is only useful if the canonical refresh path
     * later visits the same scope and can cite it. We delegate scope discovery to
     * WorkingMemoryScopeResolver so the job and refresh stay in lockstep.
     *
     * Preference order (matching the resolver's emission order):
     *   1. First `project` scope (typically derived from source_metadata.project)
     *   2. First `tag` scope (from metadata.tags or forced tags)
     *   3. global/global fallback
     *
     * @return array{0: string, 1: string}
     */
    private function resolveScope(WorkingMemoryScopeResolver $resolver, Thought $meeting): array
    {
        $scopes = $resolver->forThought($meeting);

        foreach ($scopes as $scope) {
            if ($scope['scope_type'] === 'project') {
                return ['project', $scope['scope_key']];
            }
        }

        foreach ($scopes as $scope) {
            if ($scope['scope_type'] === 'tag') {
                return ['tag', $scope['scope_key']];
            }
        }

        return ['global', 'global'];
    }

    private function decodeJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/```\s*$/', '', $trimmed) ?? $trimmed;
        }
        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
