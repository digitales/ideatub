<?php

namespace App\Services\Video;

use App\Jobs\FetchVideoTranscript;
use App\Models\Thought;
use App\Models\User;
use App\Services\Email\EmailLinkExtractor;
use App\Services\Email\YouTubeTranscriptService;
use App\Services\OpenRouterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VideoCaptureService
{
    private const ROOT_METADATA_KEYS = [
        'type',
        'video_id',
        'video_url',
        'transcript_status',
        'transcript_source',
    ];

    public const TRANSCRIPT_STATUS_PENDING = 'pending';

    public const TRANSCRIPT_STATUS_MANUAL = 'manual';

    public const TRANSCRIPT_STATUS_AVAILABLE = 'available';

    public const TRANSCRIPT_STATUS_UNAVAILABLE = 'unavailable';

    public const TRANSCRIPT_STATUS_FAILED = 'failed';

    public const TRANSCRIPT_SOURCE_NONE = 'none';

    public const TRANSCRIPT_SOURCE_PASTED = 'pasted';

    public const TRANSCRIPT_SOURCE_YOUTUBE = 'youtube';

    public static function transcriptStatusHumanLabel(mixed $status): ?string
    {
        return match ($status) {
            self::TRANSCRIPT_STATUS_PENDING => 'Fetching transcript',
            self::TRANSCRIPT_STATUS_MANUAL => 'Transcript added manually',
            self::TRANSCRIPT_STATUS_AVAILABLE => 'Transcript available',
            self::TRANSCRIPT_STATUS_UNAVAILABLE => 'Transcript unavailable',
            self::TRANSCRIPT_STATUS_FAILED => 'Transcript fetch failed',
            default => null,
        };
    }

    public static function transcriptSourceHumanLabel(mixed $source): ?string
    {
        return match ($source) {
            self::TRANSCRIPT_SOURCE_PASTED => 'Pasted',
            self::TRANSCRIPT_SOURCE_YOUTUBE => 'YouTube',
            self::TRANSCRIPT_SOURCE_NONE, null => null,
            default => is_string($source) && trim($source) !== '' ? trim($source) : null,
        };
    }

    /** Task 5: set when transcript fetch reaches a terminal state and research was requested; consumer clears after handoff. */
    public const META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH = 'video_transcript_ready_for_research';

    /** Task 5: user asked for video research on save; web checkbox / future handoff (not executed in Task 3). */
    public const META_VIDEO_RESEARCH_INTENT_PENDING = 'video_research_intent_pending';

    public const TRANSCRIPT_ERROR_REASON_MAX_LENGTH = 120;

    public function __construct(
        private EmailLinkExtractor $linkExtractor,
        private OpenRouterService $openRouter,
        private VideoThoughtContentBuilder $contentBuilder,
        private YouTubeOEmbedService $youTubeOEmbed,
        private VideoTranscriptChunker $transcriptChunker,
    ) {}

    /**
     * Embed for search; on OpenRouter failure persist without blocking capture (embedding column is nullable).
     *
     * @return array<int, float>|null
     */
    private function embedOrNull(string $text, string $context, array $logContext = []): ?array
    {
        try {
            return $this->openRouter->embed($text);
        } catch (\Throwable $e) {
            Log::warning('VideoCaptureService: OpenRouter embed failed; continuing without embedding.', array_merge([
                'context' => $context,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ], $logContext));

            return null;
        }
    }

    /**
     * Create or update a top-level video thought for this user and YouTube video id.
     *
     * @param  non-empty-string  $youtubeUrl  Any URL shape accepted by {@see EmailLinkExtractor::extractYouTubeVideoId}.
     * @param  array<string, mixed>|null  $sourceMetadata  Merged onto root {@see Thought::$source_metadata} (incoming keys win over merged existing).
     */
    public function capture(User $user, string $youtubeUrl, ?string $transcript = null, ?array $sourceMetadata = null): Thought
    {
        $videoId = $this->linkExtractor->extractYouTubeVideoId($youtubeUrl);
        if ($videoId === null || $videoId === '') {
            throw new \InvalidArgumentException('Not a recognized YouTube URL.');
        }

        $canonicalUrl = 'https://www.youtube.com/watch?v='.$videoId;
        $transcriptProvided = $transcript !== null && trim($transcript) !== '';

        return DB::transaction(function () use ($user, $videoId, $canonicalUrl, $transcriptProvided, $transcript, $sourceMetadata): Thought {
            $this->acquireVideoCaptureLock($user->id, $videoId);

            $roots = $this->videoRootsQuery($user->id, $videoId)
                ->lockForUpdate()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $root = $roots->first();
            $duplicateRoots = $roots->slice(1);
            $transcriptChildren = $roots->isNotEmpty()
                ? $this->transcriptChildrenForRoots($roots)
                : collect();
            $hadTranscriptChild = $transcriptChildren->isNotEmpty();
            $existingRootMetadata = $this->mergeExistingRootMetadata($roots);
            $existingSourceMetadata = $this->mergeExistingRootSourceMetadata($roots);
            if ($sourceMetadata !== null && $sourceMetadata !== []) {
                $existingSourceMetadata = array_merge($existingSourceMetadata ?? [], $sourceMetadata);
            }

            if ($transcriptProvided) {
                $transcriptStatus = self::TRANSCRIPT_STATUS_MANUAL;
                $transcriptSource = self::TRANSCRIPT_SOURCE_PASTED;
            } elseif (! $hadTranscriptChild) {
                $transcriptStatus = self::TRANSCRIPT_STATUS_PENDING;
                $transcriptSource = self::TRANSCRIPT_SOURCE_NONE;
            } else {
                $transcriptStatus = $this->bestMergedTranscriptStatus($roots);
                $transcriptSource = $this->bestMergedTranscriptSource($roots);

                if ($transcriptStatus === null) {
                    $transcriptStatus = self::TRANSCRIPT_STATUS_MANUAL;
                }

                if ($transcriptSource === null) {
                    $transcriptSource = self::TRANSCRIPT_SOURCE_PASTED;
                }
            }

            $rootMetadata = array_merge($existingRootMetadata, [
                'type' => 'video',
                'video_id' => $videoId,
                'video_url' => $canonicalUrl,
                'transcript_status' => $transcriptStatus,
                'transcript_source' => $transcriptSource,
            ]);

            if ($transcriptStatus === self::TRANSCRIPT_STATUS_MANUAL && $transcriptSource === self::TRANSCRIPT_SOURCE_PASTED) {
                unset($rootMetadata['transcript_error_reason']);
            }

            $rootMetadata = $this->youTubeOEmbed->enrichVideoMetadataIfMissing($rootMetadata, $canonicalUrl);
            $rootMetadata = $this->ensureVideoRootDefaultTag($rootMetadata);

            $rootContent = $this->contentBuilder->rootContentFromMetadata($canonicalUrl, $transcriptStatus, $rootMetadata);
            $rootEmbedding = $this->embedOrNull($rootContent, 'video_root', [
                'user_id' => $user->id,
                'video_id' => $videoId,
            ]);

            if ($root === null) {
                $root = Thought::create([
                    'content' => $rootContent,
                    'embedding' => $rootEmbedding,
                    'metadata' => Thought::normalizeMetadataTags($rootMetadata),
                    'user_id' => $user->id,
                    'source' => 'video',
                    'source_metadata' => $existingSourceMetadata,
                    'parent_id' => null,
                ]);
            } else {
                $root->update([
                    'content' => $rootContent,
                    'embedding' => $rootEmbedding,
                    'metadata' => Thought::normalizeMetadataTags($rootMetadata),
                    'source_metadata' => $existingSourceMetadata,
                ]);
                $root->refresh();
            }

            if ($transcriptProvided) {
                $this->replaceTranscriptChunks($user, $root, (string) $transcript, $transcriptChildren);
            } elseif ($hadTranscriptChild && $duplicateRoots->isNotEmpty()) {
                $this->consolidateTranscriptChildrenAfterDuplicateMerge($root, $transcriptChildren);
            }

            $this->reparentChildrenToRoot($root, $duplicateRoots);

            foreach ($duplicateRoots as $duplicateRoot) {
                $duplicateRoot->delete();
            }

            return $root->fresh(['comments']);
        });
    }

    /**
     * @return Collection<int, Thought>
     */
    private function transcriptChildrenForRoots(Collection $roots): Collection
    {
        $rootIds = $roots->pluck('id')->values()->all();

        return Thought::query()
            ->whereIn('parent_id', $rootIds)
            ->where('metadata->video_section_type', 'transcript')
            ->lockForUpdate()
            ->orderByRaw('CASE WHEN parent_id = ? THEN 0 ELSE 1 END', [$rootIds[0]])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Queue a transcript fetch when the root is still waiting on YouTube (no manual/pasted transcript yet).
     *
     * @return bool True if a job was dispatched; false if skipped (noop) or dispatch failed.
     */
    public function queueTranscriptFetchIfPending(Thought $root, bool $researchNow = false): bool
    {
        $root->refresh();

        if ($this->transcriptFetchShouldNoop($root)) {
            return false;
        }

        try {
            FetchVideoTranscript::dispatch($root->id, $researchNow);
        } catch (\Throwable $e) {
            Log::warning('VideoCaptureService: could not queue FetchVideoTranscript.', [
                'thought_id' => $root->id,
                'message' => $e->getMessage(),
            ]);
            report($e);

            return false;
        }

        return true;
    }

    /**
     * Capture accepted a research request, but nothing was actually queued and transcript state is still pending.
     * Clear queue-style markers so the UI/API does not imply in-flight work forever.
     */
    public function clearStalledResearchRequestMarkers(Thought $root): void
    {
        $root->refresh();

        $metadata = is_array($root->metadata) ? $root->metadata : [];
        unset(
            $metadata['research_pending'],
            $metadata[self::META_VIDEO_RESEARCH_INTENT_PENDING],
            $metadata[self::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH],
        );

        $root->update([
            'metadata' => Thought::normalizeMetadataTags($metadata),
        ]);
    }

    /**
     * Skip automatic YouTube transcript fetch when the user already supplied a transcript (capture path).
     */
    public function transcriptFetchShouldNoop(Thought $root): bool
    {
        $status = data_get($root->metadata, 'transcript_status');
        $source = data_get($root->metadata, 'transcript_source');

        if ($status === self::TRANSCRIPT_STATUS_MANUAL) {
            return true;
        }

        if ($source === self::TRANSCRIPT_SOURCE_PASTED) {
            return true;
        }

        if (is_string($status) && $status !== self::TRANSCRIPT_STATUS_PENDING) {
            return true;
        }

        return false;
    }

    /**
     * Apply a {@see YouTubeTranscriptService::fetchForUrl} result to the video root.
     * Caller must hold a transaction and row lock on $root.
     *
     * @param  array<string, mixed>  $result
     */
    public function applyTranscriptFetchResult(Thought $root, array $result, bool $researchNow): void
    {
        $videoId = data_get($root->metadata, 'video_id');
        if (is_string($videoId) && $videoId !== '') {
            $this->acquireVideoCaptureLock($root->user_id, $videoId);
        }

        if (($result['ok'] ?? false) === true) {
            $user = User::query()->whereKey($root->user_id)->first();
            if ($user === null) {
                $this->applyTranscriptFetchFailed($root, 'missing_user', $researchNow);

                return;
            }

            $text = trim((string) ($result['transcript'] ?? ''));
            if ($text === '') {
                $this->applyTranscriptFetchUnavailable($root, $researchNow);

                return;
            }

            $this->applyTranscriptFetchSuccess($root, $user, $text, $researchNow);

            return;
        }

        $reason = is_string($result['reason'] ?? null) ? $result['reason'] : 'youtube_fetch_failed';
        if ($reason === 'transcript_unavailable') {
            $this->applyTranscriptFetchUnavailable($root, $researchNow);
        } else {
            $this->applyTranscriptFetchFailed($root, $reason, $researchNow);
        }
    }

    /**
     * @param  Collection<int, Thought>  $existingTranscriptChildren
     */
    private function replaceTranscriptChunks(User $user, Thought $root, string $transcriptText, Collection $existingTranscriptChildren, string $thoughtSource = 'video'): void
    {
        $bodies = $this->transcriptChunker->splitPlainText($transcriptText);
        if ($bodies === []) {
            foreach ($existingTranscriptChildren as $row) {
                $row->delete();
            }

            return;
        }

        $chunkCount = count($bodies);
        $existingOrdered = VideoTranscriptAggregator::orderedTranscriptChildren($existingTranscriptChildren);

        foreach ($bodies as $i => $body) {
            $childMetadata = [
                'video_section_type' => 'transcript',
                'transcript_chunk_index' => $i,
                'transcript_chunk_count' => $chunkCount,
            ];
            $content = $this->contentBuilder->transcriptContent($body);
            $embedding = $this->embedOrNull($content, 'transcript_chunk', [
                'user_id' => $user->id,
                'root_thought_id' => (string) $root->id,
                'transcript_chunk_index' => $i,
            ]);

            $existing = $existingOrdered->get($i);
            if ($existing !== null) {
                $existing->update([
                    'content' => $content,
                    'embedding' => $embedding,
                    'metadata' => Thought::normalizeMetadataTags($childMetadata),
                    'parent_id' => $root->id,
                    'source' => $thoughtSource,
                ]);
            } else {
                Thought::create([
                    'content' => $content,
                    'embedding' => $embedding,
                    'metadata' => Thought::normalizeMetadataTags($childMetadata),
                    'user_id' => $user->id,
                    'source' => $thoughtSource,
                    'source_metadata' => null,
                    'parent_id' => $root->id,
                ]);
            }
        }

        foreach ($existingOrdered->slice($chunkCount) as $extra) {
            $extra->delete();
        }
    }

    private function transcriptChildrenForRootLocked(Thought $root): Collection
    {
        return Thought::query()
            ->where('parent_id', $root->id)
            ->where('metadata->video_section_type', 'transcript')
            ->lockForUpdate()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    private function applyTranscriptFetchSuccess(Thought $root, User $user, string $transcriptText, bool $researchNow): void
    {
        $children = $this->transcriptChildrenForRootLocked($root);
        $this->replaceTranscriptChunks($user, $root, $transcriptText, $children, self::TRANSCRIPT_SOURCE_YOUTUBE);

        $metadata = $this->mergeRootMetadataForTranscriptState($root, [
            'transcript_status' => self::TRANSCRIPT_STATUS_AVAILABLE,
            'transcript_source' => self::TRANSCRIPT_SOURCE_YOUTUBE,
        ]);
        unset($metadata['transcript_error_reason']);

        if ($researchNow) {
            $metadata['research_pending'] = true;
            $metadata[self::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH] = true;
        } else {
            unset($metadata[self::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH]);
        }

        $canonicalUrl = $this->canonicalVideoUrlForThought($root);
        $metadata = $this->youTubeOEmbed->enrichVideoMetadataIfMissing($metadata, $canonicalUrl);
        $metadata = $this->ensureVideoRootDefaultTag($metadata);
        $rootContent = $this->contentBuilder->rootContentFromMetadata($canonicalUrl, self::TRANSCRIPT_STATUS_AVAILABLE, $metadata);
        $rootEmbedding = $this->embedOrNull($rootContent, 'video_root_after_transcript', [
            'root_thought_id' => (string) $root->id,
        ]);

        $root->update([
            'content' => $rootContent,
            'embedding' => $rootEmbedding,
            'metadata' => Thought::normalizeMetadataTags($metadata),
        ]);
    }

    private function applyTranscriptFetchUnavailable(Thought $root, bool $researchNow): void
    {
        $metadata = $this->mergeRootMetadataForTranscriptState($root, [
            'transcript_status' => self::TRANSCRIPT_STATUS_UNAVAILABLE,
            'transcript_source' => self::TRANSCRIPT_SOURCE_NONE,
        ]);
        unset($metadata['transcript_error_reason']);

        if ($researchNow) {
            $metadata['research_pending'] = true;
            $metadata[self::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH] = true;
        } else {
            unset($metadata[self::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH]);
        }

        $canonicalUrl = $this->canonicalVideoUrlForThought($root);
        $metadata = $this->youTubeOEmbed->enrichVideoMetadataIfMissing($metadata, $canonicalUrl);
        $metadata = $this->ensureVideoRootDefaultTag($metadata);
        $rootContent = $this->contentBuilder->rootContentFromMetadata($canonicalUrl, self::TRANSCRIPT_STATUS_UNAVAILABLE, $metadata);
        $rootEmbedding = $this->embedOrNull($rootContent, 'video_root_transcript_unavailable', [
            'root_thought_id' => (string) $root->id,
        ]);

        $root->update([
            'content' => $rootContent,
            'embedding' => $rootEmbedding,
            'metadata' => Thought::normalizeMetadataTags($metadata),
        ]);
    }

    private function applyTranscriptFetchFailed(Thought $root, string $reason, bool $researchNow): void
    {
        $metadata = $this->mergeRootMetadataForTranscriptState($root, [
            'transcript_status' => self::TRANSCRIPT_STATUS_FAILED,
            'transcript_source' => self::TRANSCRIPT_SOURCE_NONE,
            'transcript_error_reason' => $this->boundTranscriptErrorReason($reason),
        ]);

        if ($researchNow) {
            $metadata['research_pending'] = true;
            $metadata[self::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH] = true;
        } else {
            unset($metadata[self::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH]);
        }

        $canonicalUrl = $this->canonicalVideoUrlForThought($root);
        $metadata = $this->youTubeOEmbed->enrichVideoMetadataIfMissing($metadata, $canonicalUrl);
        $metadata = $this->ensureVideoRootDefaultTag($metadata);
        $rootContent = $this->contentBuilder->rootContentFromMetadata($canonicalUrl, self::TRANSCRIPT_STATUS_FAILED, $metadata);
        $rootEmbedding = $this->embedOrNull($rootContent, 'video_root_transcript_failed', [
            'root_thought_id' => (string) $root->id,
        ]);

        $root->update([
            'content' => $rootContent,
            'embedding' => $rootEmbedding,
            'metadata' => Thought::normalizeMetadataTags($metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $transcriptKeys
     * @return array<string, mixed>
     */
    private function mergeRootMetadataForTranscriptState(Thought $root, array $transcriptKeys): array
    {
        $metadata = is_array($root->metadata) ? $root->metadata : [];

        $canonicalUrl = $this->canonicalVideoUrlForThought($root);
        if ($canonicalUrl !== '') {
            $metadata['video_url'] = $canonicalUrl;
        }

        return array_merge($metadata, $transcriptKeys);
    }

    private function canonicalVideoUrlForThought(Thought $root): string
    {
        $videoId = data_get($root->metadata, 'video_id');
        if (is_string($videoId) && $videoId !== '') {
            return 'https://www.youtube.com/watch?v='.$videoId;
        }

        $videoUrl = data_get($root->metadata, 'video_url');

        return is_string($videoUrl) ? $videoUrl : '';
    }

    private function boundTranscriptErrorReason(string $reason): string
    {
        $trimmed = trim($reason);
        if ($trimmed === '') {
            return 'youtube_fetch_failed';
        }

        if (strlen($trimmed) <= self::TRANSCRIPT_ERROR_REASON_MAX_LENGTH) {
            return $trimmed;
        }

        return substr($trimmed, 0, self::TRANSCRIPT_ERROR_REASON_MAX_LENGTH);
    }

    /**
     * @return Builder<Thought>
     */
    private function videoRootsQuery(int $userId, string $videoId): Builder
    {
        return Thought::query()
            ->where('user_id', $userId)
            ->whereNull('parent_id')
            ->where('metadata->type', 'video')
            ->where('metadata->video_id', $videoId);
    }

    /**
     * After duplicate video roots merge, reparent every transcript child onto the surviving root and drop
     * duplicate rows that share the same chunk index (keeps the newest by {@see Thought::$updated_at}).
     *
     * @param  Collection<int, Thought>  $transcriptChildren
     */
    private function consolidateTranscriptChildrenAfterDuplicateMerge(Thought $root, Collection $transcriptChildren): void
    {
        if ($transcriptChildren->isEmpty()) {
            return;
        }

        foreach ($transcriptChildren as $child) {
            if ($child->parent_id !== $root->id) {
                $child->update(['parent_id' => $root->id]);
            }
        }

        $byIndex = [];
        foreach (VideoTranscriptAggregator::orderedTranscriptChildren($transcriptChildren) as $child) {
            $idx = (int) data_get($child->metadata, 'transcript_chunk_index', 0);
            $byIndex[$idx][] = $child;
        }
        ksort($byIndex);

        $toDelete = [];
        $keepers = [];
        foreach ($byIndex as $group) {
            if (count($group) === 1) {
                $keepers[] = $group[0];

                continue;
            }
            usort($group, fn (Thought $a, Thought $b): int => $b->updated_at <=> $a->updated_at);
            $keepers[] = $group[0];
            foreach (array_slice($group, 1) as $dup) {
                $toDelete[] = $dup;
            }
        }

        foreach ($toDelete as $dup) {
            $dup->delete();
        }

        $orderedKeepers = collect($keepers)->sortBy(function (Thought $t): array {
            return [
                (int) data_get($t->metadata, 'transcript_chunk_index', 0),
                $t->created_at?->timestamp ?? 0,
                (string) $t->id,
            ];
        })->values();

        $count = $orderedKeepers->count();
        foreach ($orderedKeepers as $i => $thought) {
            $meta = is_array($thought->metadata) ? $thought->metadata : [];
            $meta['video_section_type'] = 'transcript';
            $meta['transcript_chunk_index'] = $i;
            $meta['transcript_chunk_count'] = $count;
            $thought->update([
                'metadata' => Thought::normalizeMetadataTags($meta),
            ]);
        }
    }

    /**
     * Ensure every video root has at least the `video` tag so Stream and detail UIs stay consistent with research capture.
     *
     * @param  array<string, mixed>  $rootMetadata
     * @return array<string, mixed>
     */
    private function ensureVideoRootDefaultTag(array $rootMetadata): array
    {
        $tags = isset($rootMetadata['tags']) && is_array($rootMetadata['tags'])
            ? $rootMetadata['tags']
            : [];
        $lower = array_map(fn ($t) => mb_strtolower(trim((string) $t)), $tags);
        if (! in_array('video', $lower, true)) {
            $tags[] = 'video';
        }
        $rootMetadata['tags'] = $tags;

        return $rootMetadata;
    }

    private function acquireVideoCaptureLock(?int $userId, string $videoId): void
    {
        if ($userId === null) {
            return;
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', [
            sprintf('video-capture:%d:%s', $userId, $videoId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeExistingRootMetadata(Collection $roots): array
    {
        $merged = [];

        foreach ($roots as $root) {
            if (! is_array($root->metadata)) {
                continue;
            }

            foreach ($root->metadata as $key => $value) {
                if (in_array($key, self::ROOT_METADATA_KEYS, true)) {
                    continue;
                }

                if ($value !== null && $value !== '') {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mergeExistingRootSourceMetadata(Collection $roots): ?array
    {
        $merged = [];

        foreach ($roots as $root) {
            if (! is_array($root->source_metadata)) {
                continue;
            }

            foreach ($root->source_metadata as $key => $value) {
                if ($this->isMeaningfulMergedValue($value)) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged === [] ? null : $merged;
    }

    private function bestMergedTranscriptStatus(Collection $roots): ?string
    {
        $preferred = $this->firstPreferredMetadataValue($roots, 'transcript_status', [
            self::TRANSCRIPT_STATUS_MANUAL,
        ]);

        if ($preferred !== null) {
            return $preferred;
        }

        return $this->firstNonEmptyMetadataValueExcluding($roots, 'transcript_status', [
            self::TRANSCRIPT_STATUS_PENDING,
        ]);
    }

    private function bestMergedTranscriptSource(Collection $roots): ?string
    {
        $preferred = $this->firstPreferredMetadataValue($roots, 'transcript_source', [
            self::TRANSCRIPT_SOURCE_PASTED,
        ]);

        if ($preferred !== null) {
            return $preferred;
        }

        return $this->firstNonEmptyMetadataValueExcluding($roots, 'transcript_source', [
            self::TRANSCRIPT_SOURCE_NONE,
        ]);
    }

    /**
     * @param  list<string>  $preferredValues
     */
    private function firstPreferredMetadataValue(Collection $roots, string $key, array $preferredValues): ?string
    {
        foreach ($preferredValues as $preferredValue) {
            foreach ($roots as $root) {
                $value = data_get($root->metadata, $key);
                if (is_string($value) && $value === $preferredValue) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $excludedValues
     */
    private function firstNonEmptyMetadataValueExcluding(Collection $roots, string $key, array $excludedValues): ?string
    {
        foreach ($roots as $root) {
            $value = data_get($root->metadata, $key);
            if (is_string($value) && $value !== '' && ! in_array($value, $excludedValues, true)) {
                return $value;
            }
        }

        return null;
    }

    private function isMeaningfulMergedValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_array($value) && $value === []) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, Thought>  $duplicateRoots
     */
    private function reparentChildrenToRoot(Thought $root, Collection $duplicateRoots): void
    {
        if ($duplicateRoots->isEmpty()) {
            return;
        }

        Thought::query()
            ->whereIn('parent_id', $duplicateRoots->pluck('id')->all())
            ->lockForUpdate()
            ->update([
                'parent_id' => $root->id,
            ]);
    }
}
