<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use App\Services\WorkingMemory\Compactions\TopicDigestPromptBuilder;
use App\Support\Json\LlmJsonDecoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BuildTopicDigestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $userId,
        public string $scopeType,
        public string $scopeKey,
        public string $topic,
    ) {}

    public function handle(
        TopicDigestPromptBuilder $promptBuilder,
        OpenRouterService $openRouter,
        CompactionVersionWriter $writer,
    ): void {
        if ($this->userId <= 0 || $this->scopeType === '' || $this->scopeKey === '' || $this->topic === '') {
            return;
        }

        $thoughts = $this->collectTopicThoughts();
        if ($thoughts->isEmpty()) {
            return;
        }

        try {
            $prompt = $promptBuilder->build($this->scopeType, $this->scopeKey, $this->topic, $thoughts);
            $model = (string) config('working_memory.authoring_digest_model', '');
            $temperature = config('working_memory.authoring_digest_temperature');
            $raw = $openRouter->researchFromPrompt(
                $prompt,
                $model !== '' ? $model : null,
                is_numeric($temperature) ? (float) $temperature : null,
            );

            $decoded = LlmJsonDecoder::decode($raw);
            if ($decoded === null) {
                Log::warning('BuildTopicDigestJob: model returned non-JSON output.', [
                    'user_id' => $this->userId,
                    'scope_type' => $this->scopeType,
                    'scope_key' => $this->scopeKey,
                    'topic' => $this->topic,
                ]);

                return;
            }

            $writer->write(
                userId: $this->userId,
                scopeType: $this->scopeType,
                scopeKey: $this->scopeKey,
                buildType: 'compaction:topic-digest',
                summaryMarkdown: (string) ($decoded['summary_markdown'] ?? ''),
                structuredSections: is_array($decoded['structured_sections'] ?? null) ? $decoded['structured_sections'] : [],
                references: is_array($decoded['references'] ?? null) ? $decoded['references'] : [],
                sourceThoughtIds: $thoughts->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            );
        } catch (Throwable $e) {
            Log::warning('BuildTopicDigestJob failed.', [
                'user_id' => $this->userId,
                'scope_type' => $this->scopeType,
                'scope_key' => $this->scopeKey,
                'topic' => $this->topic,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return Collection<int, Thought>
     */
    private function collectTopicThoughts(): Collection
    {
        $query = Thought::query()
            ->where('user_id', $this->userId)
            ->whereJsonContains('metadata->tags', $this->topic)
            ->orderByDesc('created_at')
            ->limit(200);

        if ($this->scopeType === 'project') {
            $query->where(function ($q): void {
                $q->where('source_metadata->project', $this->scopeKey);
                if (Str::isUuid($this->scopeKey)) {
                    $q->orWhereHas('projects', fn ($p) => $p->where('projects.id', $this->scopeKey));
                }
            });
        } elseif ($this->scopeType === 'tag') {
            $query->whereJsonContains('metadata->tags', $this->scopeKey);
        }

        return $query->get();
    }
}
