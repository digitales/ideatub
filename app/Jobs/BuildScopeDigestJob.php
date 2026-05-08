<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use App\Services\WorkingMemory\Compactions\ScopeDigestPromptBuilder;
use App\Support\Json\LlmDecodeFailureLogContext;
use App\Support\Json\LlmJsonDecoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BuildScopeDigestJob implements ShouldQueue
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
    ) {}

    public function handle(
        ScopeDigestPromptBuilder $promptBuilder,
        OpenRouterService $openRouter,
        CompactionVersionWriter $writer,
    ): void {
        if ($this->userId <= 0 || $this->scopeType === '' || $this->scopeKey === '') {
            return;
        }

        $now = Carbon::now();
        $windowDays = (int) config('working_memory.digest_window_days', 7);
        $minThoughts = (int) config('working_memory.digest_min_thoughts', 3);
        $windowStart = $now->copy()->subDays($windowDays);
        $windowEnd = $now->copy();

        if ($this->hasFreshDigest($windowStart)) {
            return;
        }

        $thoughts = $this->collectScopedThoughts($windowStart, $windowEnd);
        if ($thoughts->count() < $minThoughts) {
            return;
        }

        try {
            $prompt = $promptBuilder->build(
                $this->scopeType,
                $this->scopeKey,
                $windowStart,
                $windowEnd,
                $thoughts,
            );
            $model = (string) config('working_memory.authoring_digest_model', '');
            $temperature = config('working_memory.authoring_digest_temperature');
            $raw = $openRouter->researchFromPrompt(
                $prompt,
                $model !== '' ? $model : null,
                is_numeric($temperature) ? (float) $temperature : null,
            );

            $decoded = LlmJsonDecoder::decode($raw);
            if ($decoded === null) {
                Log::warning('BuildScopeDigestJob: model returned non-JSON output.', LlmDecodeFailureLogContext::withOptionalRawPreview([
                    'user_id' => $this->userId,
                    'scope_type' => $this->scopeType,
                    'scope_key' => $this->scopeKey,
                ], $raw));

                return;
            }

            $writer->write(
                userId: $this->userId,
                scopeType: $this->scopeType,
                scopeKey: $this->scopeKey,
                buildType: 'compaction:weekly-digest',
                summaryMarkdown: (string) ($decoded['summary_markdown'] ?? ''),
                structuredSections: is_array($decoded['structured_sections'] ?? null) ? $decoded['structured_sections'] : [],
                references: is_array($decoded['references'] ?? null) ? $decoded['references'] : [],
                sourceThoughtIds: $thoughts->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            );
        } catch (Throwable $e) {
            Log::warning('BuildScopeDigestJob failed.', [
                'user_id' => $this->userId,
                'scope_type' => $this->scopeType,
                'scope_key' => $this->scopeKey,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function hasFreshDigest(Carbon $windowStart): bool
    {
        $memory = WorkingMemory::query()
            ->where('user_id', $this->userId)
            ->where('scope_type', $this->scopeType)
            ->where('scope_key', $this->scopeKey)
            ->first();

        if ($memory === null) {
            return false;
        }

        return WorkingMemoryVersion::query()
            ->where('working_memory_id', $memory->id)
            ->where('build_type', 'compaction:weekly-digest')
            ->where('created_at', '>=', $windowStart)
            ->exists();
    }

    /**
     * @return Collection<int, Thought>
     */
    private function collectScopedThoughts(Carbon $windowStart, Carbon $windowEnd): Collection
    {
        $query = Thought::query()
            ->where('user_id', $this->userId)
            ->whereBetween('created_at', [$windowStart, $windowEnd])
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
        // global / insights: no narrowing.

        return $query->get();
    }
}
