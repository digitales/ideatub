<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use App\Services\WorkingMemory\Compactions\MeetingCompactionPromptBuilder;
use App\Services\WorkingMemory\Compactions\MeetingPrimaryScopeResolver;
use App\Support\Json\LlmDecodeFailureLogContext;
use App\Support\Json\LlmJsonDecoder;
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
        MeetingPrimaryScopeResolver $primaryScopeResolver,
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

        [$scopeType, $scopeKey] = $primaryScopeResolver->forThought($meeting);

        try {
            $prompt = $promptBuilder->build($meeting);
            $model = (string) config('working_memory.authoring_meeting_compaction_model', '');
            $temperature = config('working_memory.authoring_meeting_compaction_temperature');
            $raw = $openRouter->researchFromPrompt(
                $prompt,
                $model !== '' ? $model : null,
                is_numeric($temperature) ? (float) $temperature : null,
            );
            $decoded = LlmJsonDecoder::decode($raw);

            if ($decoded === null) {
                Log::warning('SynthesizeMeetingCompactionJob: model returned non-JSON output.', LlmDecodeFailureLogContext::withOptionalRawPreview([
                    'thought_id' => $meeting->id,
                ], $raw));

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
}
