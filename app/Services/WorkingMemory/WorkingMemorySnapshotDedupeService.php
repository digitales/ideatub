<?php

namespace App\Services\WorkingMemory;

use App\Jobs\RetryWorkingMemorySupersedeJob;
use App\Models\Thought;
use App\Services\ThoughtCaptureService;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkingMemorySnapshotDedupeService
{
    public function __construct(
        private readonly ThoughtCaptureService $captureService,
        private readonly WorkingMemoryContentFingerprint $fingerprint,
        private readonly WorkingMemoryDedupeFamilyResolver $familyResolver,
        private readonly WorkingMemorySnapshotSuperseder $snapshotSuperseder,
    ) {}

    /**
     * @param  array<string, mixed>|null  $sourceMetadata
     * @param  list<string>  $extraTags
     * @return array<string, mixed>
     */
    public function capture(
        int $userId,
        string $content,
        string $docType,
        ?array $sourceMetadata,
        ?string $planSlug,
        ?string $parentId,
        ?string $filePath,
        ?string $project,
        array $extraTags,
        bool $noChunking,
        bool $strictContentHash,
    ): array {
        $fingerprintHash = $this->fingerprint->hash($content, $strictContentHash);
        $dedupeFamily = $this->familyResolver->resolveForCapture($planSlug, $extraTags, $project);

        $current = $this->findCurrentSnapshot($userId, $dedupeFamily, $fingerprintHash);
        if ($current !== null && $current->content_fingerprint === $fingerprintHash) {
            return $this->buildResponse($current, deduplicated: true, fingerprintHash: $fingerprintHash, dedupeFamily: $dedupeFamily);
        }

        return DB::transaction(function () use (
            $userId,
            $content,
            $docType,
            $sourceMetadata,
            $planSlug,
            $parentId,
            $filePath,
            $project,
            $extraTags,
            $noChunking,
            $fingerprintHash,
            $dedupeFamily,
            $current,
        ): array {
            $enrichedMetadata = $this->mergeWorkingMemoryMetadata($sourceMetadata, $fingerprintHash, $dedupeFamily);

            $result = $this->captureService->create([
                'content' => $content,
                'user_id' => $userId,
                'parent_id' => $parentId,
                'source' => $docType,
                'source_metadata' => $enrichedMetadata,
                'no_chunking' => $noChunking,
                'plan_slug' => $planSlug,
                'doc_type' => $docType,
                'file_path' => $filePath,
                'project' => $project,
                'extra_tags' => $extraTags,
            ]);

            $thought = $result['chunked'] ? $result['root'] : $result['thought'];
            $thought->forceFill(['content_fingerprint' => $fingerprintHash])->save();

            $supersededThoughtId = null;
            try {
                $supersededThoughtId = $this->supersedeOtherCurrents($userId, $dedupeFamily, $thought, $current);
            } catch (Throwable $e) {
                RetryWorkingMemorySupersedeJob::dispatch(
                    $userId,
                    $dedupeFamily,
                    (string) $thought->id,
                    null,
                );
                report($e);
            }

            $out = $result['chunked']
                ? ['id' => $thought->id, 'chunked' => true, 'section_ids' => $result['section_ids']]
                : ['id' => $thought->id];

            if ($planSlug !== null) {
                $out['plan_slug'] = $planSlug;
            }
            if ($docType !== 'plan') {
                $out['doc_type'] = $docType;
            }

            return array_merge($out, [
                'deduplicated' => false,
                'content_fingerprint' => $fingerprintHash,
                'dedupe_family' => $dedupeFamily,
                'superseded_thought_id' => $supersededThoughtId,
            ]);
        });
    }

    private function findCurrentSnapshot(int $userId, string $dedupeFamily, string $fingerprintHash): ?Thought
    {
        $byFingerprint = Thought::query()
            ->where('user_id', $userId)
            ->where('content_fingerprint', $fingerprintHash)
            ->visibleInStream()
            ->orderByDesc('created_at')
            ->first();

        if ($byFingerprint instanceof Thought) {
            return $byFingerprint;
        }

        return Thought::query()
            ->where('user_id', $userId)
            ->where('source_metadata->working_memory->dedupe_family', $dedupeFamily)
            ->visibleInStream()
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>|null  $sourceMetadata
     * @return array<string, mixed>
     */
    private function mergeWorkingMemoryMetadata(?array $sourceMetadata, string $fingerprintHash, string $dedupeFamily): array
    {
        $metadata = is_array($sourceMetadata) ? $sourceMetadata : [];
        $metadata['working_memory'] = [
            'dedupe_family' => $dedupeFamily,
            'content_fingerprint' => $fingerprintHash,
            'is_current' => true,
            'superseded_at' => null,
            'superseded_by_thought_id' => null,
        ];

        return $metadata;
    }

    private function supersedeOtherCurrents(
        int $userId,
        string $dedupeFamily,
        Thought $winner,
        ?Thought $knownCurrent,
    ): ?string {
        $firstSuperseded = null;

        $others = Thought::query()
            ->where('user_id', $userId)
            ->whereKeyNot($winner->id)
            ->visibleInStream()
            ->get()
            ->filter(function (Thought $thought) use ($dedupeFamily): bool {
                $stored = data_get($thought->source_metadata, 'working_memory.dedupe_family');
                if (is_string($stored) && $stored === $dedupeFamily) {
                    return true;
                }

                $tags = is_array($thought->metadata['tags'] ?? null)
                    ? $thought->metadata['tags']
                    : [];

                return $this->familyResolver->resolveForCapture(
                    planSlug: data_get($thought->source_metadata, 'plan_slug'),
                    extraTags: $tags,
                    project: data_get($thought->source_metadata, 'project'),
                ) === $dedupeFamily;
            });

        if ($knownCurrent !== null
            && $knownCurrent->id !== $winner->id
            && ! $others->contains(fn (Thought $t): bool => $t->id === $knownCurrent->id)) {
            $others->push($knownCurrent);
        }

        foreach ($others as $prior) {
            $this->snapshotSuperseder->supersede($prior, $winner);
            $firstSuperseded ??= (string) $prior->id;
        }

        return $firstSuperseded;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponse(
        Thought $thought,
        bool $deduplicated,
        string $fingerprintHash,
        string $dedupeFamily,
    ): array {
        $out = [
            'id' => (string) $thought->id,
            'deduplicated' => $deduplicated,
            'content_fingerprint' => $fingerprintHash,
            'dedupe_family' => $dedupeFamily,
            'superseded_thought_id' => null,
        ];
        $planSlug = $thought->source_metadata['plan_slug'] ?? null;
        if (is_string($planSlug) && $planSlug !== '') {
            $out['plan_slug'] = $planSlug;
        }

        return $out;
    }
}
