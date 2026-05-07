<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use App\Services\WorkingMemory\Compactions\MeetingCompactionPromptBuilder;
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

        [$scopeType, $scopeKey] = $this->resolveScope($meeting);

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
     * @return array{0: string, 1: string}
     */
    private function resolveScope(Thought $meeting): array
    {
        $tags = collect(data_get($meeting->metadata, 'tags', []))
            ->map(fn ($t): string => trim((string) $t))
            ->filter()
            ->values();

        $project = $tags->first(fn (string $t): bool => str_starts_with($t, 'project:'));
        if ($project !== null) {
            return ['project', substr($project, strlen('project:'))];
        }

        $client = $tags->first(fn (string $t): bool => str_starts_with($t, 'client:'));
        if ($client !== null) {
            return ['project', substr($client, strlen('client:'))];
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
