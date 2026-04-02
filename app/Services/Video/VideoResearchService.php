<?php

namespace App\Services\Video;

use App\Jobs\RunVideoResearch;
use App\Models\Thought;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoResearchService
{
    public const HEADING_SUMMARY = '## Summary';

    public const HEADING_KEY_POINTS = '## Key Points';

    public const HEADING_POSITIVES = '## Positives';

    public const HEADING_NEGATIVES = '## Negatives';

    public const HEADING_SOURCE_NOTES = '## Source Notes';

    /**
     * @var list<string>
     */
    public const REQUIRED_SECTION_HEADINGS = [
        self::HEADING_SUMMARY,
        self::HEADING_KEY_POINTS,
        self::HEADING_POSITIVES,
        self::HEADING_NEGATIVES,
        self::HEADING_SOURCE_NOTES,
    ];

    public function __construct(
        private OpenRouterService $openRouter,
        private VideoResearchPromptBuilder $promptBuilder,
    ) {}

    /**
     * After a transcript fetch reaches a terminal state, queue video research if the user requested it.
     */
    public function queueRunAfterTranscriptTerminalIfEligible(string $videoThoughtId, bool $fetchJobHadResearchNow): void
    {
        $root = Thought::query()->find($videoThoughtId);
        if ($root === null || $root->parent_id !== null) {
            return;
        }

        if (data_get($root->metadata, 'type') !== 'video') {
            return;
        }

        if (! $this->isTerminalTranscriptFetchState($root)) {
            return;
        }

        $meta = is_array($root->metadata) ? $root->metadata : [];
        $intent = ! empty($meta[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING]);
        if (! $fetchJobHadResearchNow && ! $intent) {
            return;
        }

        RunVideoResearch::dispatch($videoThoughtId);
    }

    /**
     * Run OpenRouter research, save a new linked research thought, update latest pointer on the video root.
     *
     * @throws \Throwable
     */
    public function runAndSaveForVideoRoot(Thought $root): Thought
    {
        $rootId = $root->id;

        try {
            $snapshot = $this->videoResearchSnapshot($rootId);
            $prompt = $this->promptBuilder->build(
                $snapshot['root'],
                $snapshot['transcript_context_available'],
                $snapshot['transcript_child'],
                $snapshot['transcript_state'],
            );
            $raw = $this->openRouter->researchFromPrompt($prompt);
            $body = $this->normalizeSavedMarkdown(
                trim($raw),
                $snapshot['transcript_context_available'],
                $snapshot['transcript_state'],
            );

            return DB::transaction(function () use ($rootId, $snapshot, $body): Thought {
                $locked = Thought::query()->whereKey($rootId)->lockForUpdate()->first();
                if ($locked === null || $locked->parent_id !== null) {
                    throw new \RuntimeException('Video research requires a video root thought.');
                }

                if (data_get($locked->metadata, 'type') !== 'video') {
                    throw new \RuntimeException('Thought is not a video root.');
                }

                $priorResearchId = data_get($locked->metadata, 'research_thought_id');
                $priorResearchId = is_string($priorResearchId) && $priorResearchId !== '' ? $priorResearchId : null;

                $videoId = (string) (data_get($locked->metadata, 'video_id') ?? '');

                $enrichment = $this->enrichVideoResearchArtifact($body, $locked);

                $researchMetadata = Thought::normalizeMetadataTags([
                    'type' => 'research',
                    'video_thought_id' => $locked->id,
                    'tags' => $enrichment['tags'],
                ]);

                if ($enrichment['people'] !== []) {
                    $researchMetadata['people'] = $enrichment['people'];
                }
                if ($enrichment['action_items'] !== []) {
                    $researchMetadata['action_items'] = $enrichment['action_items'];
                }

                if ($priorResearchId !== null) {
                    $researchMetadata['parent_research_thought_id'] = $priorResearchId;
                }

                $sourceMetadata = [
                    'video_thought_id' => $locked->id,
                    'video_id' => $videoId,
                    'transcript_context_available' => $snapshot['transcript_context_available'],
                ];

                $research = Thought::create([
                    'content' => $body,
                    'embedding' => $enrichment['embedding'],
                    'metadata' => $researchMetadata,
                    'user_id' => $locked->user_id,
                    'source' => 'research',
                    'source_metadata' => $sourceMetadata,
                    'parent_id' => null,
                ]);

                $rootMeta = is_array($locked->metadata) ? $locked->metadata : [];
                $rootMeta['research_thought_id'] = $research->id;
                unset(
                    $rootMeta['research_pending'],
                    $rootMeta[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING],
                    $rootMeta[VideoCaptureService::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH],
                );

                $rootMeta = $this->mergeLinkedResearchTagsIntoVideoRoot($rootMeta, $researchMetadata);

                $locked->update([
                    'metadata' => Thought::normalizeMetadataTags($rootMeta),
                ]);

                return $research;
            });
        } catch (\Throwable $e) {
            $this->clearVideoResearchFailureFlags($rootId);
            Log::warning('VideoResearchService: research failed.', [
                'video_thought_id' => $rootId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Clear queue markers after a failed run without changing {@see research_thought_id}.
     */
    public function clearVideoResearchFailureFlags(string $videoThoughtId): void
    {
        DB::transaction(function () use ($videoThoughtId): void {
            $locked = Thought::query()->whereKey($videoThoughtId)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $meta = is_array($locked->metadata) ? $locked->metadata : [];
            unset(
                $meta['research_pending'],
                $meta[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING],
                $meta[VideoCaptureService::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH],
            );
            $locked->update(['metadata' => Thought::normalizeMetadataTags($meta)]);
        });
    }

    public function isTerminalTranscriptFetchState(Thought $root): bool
    {
        $status = data_get($root->metadata, 'transcript_status');

        return in_array($status, [
            VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
            VideoCaptureService::TRANSCRIPT_STATUS_UNAVAILABLE,
            VideoCaptureService::TRANSCRIPT_STATUS_FAILED,
            VideoCaptureService::TRANSCRIPT_STATUS_MANUAL,
        ], true);
    }

    /**
     * Copy topic tags from the new research thought onto the video root (excluding `research`) so the detail header,
     * Stream, and sidebar stay aligned with linked research without treating the root as a research card.
     *
     * @param  array<string, mixed>  $videoRootMeta
     * @param  array<string, mixed>  $researchMeta
     * @return array<string, mixed>
     */
    private function mergeLinkedResearchTagsIntoVideoRoot(array $videoRootMeta, array $researchMeta): array
    {
        $researchTags = $researchMeta['tags'] ?? [];
        if (! is_array($researchTags) || $researchTags === []) {
            return $videoRootMeta;
        }

        $rootTags = isset($videoRootMeta['tags']) && is_array($videoRootMeta['tags'])
            ? $videoRootMeta['tags']
            : [];
        $rootLower = array_map(fn ($t) => mb_strtolower(trim((string) $t)), $rootTags);

        foreach ($researchTags as $t) {
            $norm = mb_strtolower(trim((string) $t));
            if ($norm === '' || $norm === 'research') {
                continue;
            }
            if (! in_array($norm, $rootLower, true)) {
                $rootTags[] = $norm;
                $rootLower[] = $norm;
            }
        }

        $videoRootMeta['tags'] = $rootTags;

        return $videoRootMeta;
    }

    private function transcriptContextAvailableAtRunStart(Thought $root, ?Thought $transcriptChild): bool
    {
        if ($transcriptChild === null) {
            return false;
        }

        $raw = trim($transcriptChild->getDecodedContent());
        $raw = preg_replace('/^##\s+Transcript\s*/im', '', $raw) ?? $raw;
        $raw = trim($raw);

        return $raw !== '';
    }

    /**
     * @return array{
     *     root: Thought,
     *     transcript_child: ?Thought,
     *     transcript_context_available: bool,
     *     transcript_state: array{status: string, source: string}
     * }
     */
    private function videoResearchSnapshot(string $rootId): array
    {
        $root = Thought::query()->find($rootId);
        if ($root === null || $root->parent_id !== null) {
            throw new \RuntimeException('Video research requires a video root thought.');
        }

        if (data_get($root->metadata, 'type') !== 'video') {
            throw new \RuntimeException('Thought is not a video root.');
        }

        $transcriptChild = Thought::query()
            ->where('parent_id', $root->id)
            ->where('metadata->video_section_type', 'transcript')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        $transcriptState = [
            'status' => (string) (data_get($root->metadata, 'transcript_status') ?? ''),
            'source' => (string) (data_get($root->metadata, 'transcript_source') ?? ''),
        ];

        return [
            'root' => $root,
            'transcript_child' => $transcriptChild,
            'transcript_context_available' => $this->transcriptContextAvailableAtRunStart($root, $transcriptChild),
            'transcript_state' => $transcriptState,
        ];
    }

    /**
     * After the research markdown is finalized, align with normal thought capture: topic tags from OpenRouter
     * {@see OpenRouterService::extractMetadata}, optional people/action_items, and an embedding for semantic search.
     * Failures are logged; we still persist research with at least `research` and `video` tags.
     *
     * @return array{tags: list<string>, people: list<string>, action_items: list<string>, embedding: array<int, float>|null}
     */
    private function enrichVideoResearchArtifact(string $body, Thought $videoRoot): array
    {
        $tags = ['research', 'video'];
        $videoMetaTags = data_get($videoRoot->metadata, 'tags');
        if (is_array($videoMetaTags)) {
            foreach ($videoMetaTags as $t) {
                $norm = mb_strtolower(trim((string) $t));
                if ($norm !== '' && ! in_array($norm, $tags, true)) {
                    $tags[] = $norm;
                }
            }
        }

        $people = [];
        $actionItems = [];
        try {
            $extracted = $this->openRouter->extractMetadata($body);
            foreach ($extracted['tags'] ?? [] as $t) {
                $norm = mb_strtolower(trim((string) $t));
                if ($norm !== '' && ! in_array($norm, $tags, true)) {
                    $tags[] = $norm;
                }
            }
            foreach ($extracted['people'] ?? [] as $p) {
                $p = trim((string) $p);
                if ($p !== '' && ! in_array($p, $people, true)) {
                    $people[] = $p;
                }
            }
            foreach ($extracted['action_items'] ?? [] as $a) {
                $a = trim((string) $a);
                if ($a !== '' && ! in_array($a, $actionItems, true)) {
                    $actionItems[] = $a;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('VideoResearchService: extractMetadata failed after research generation.', [
                'video_thought_id' => $videoRoot->id,
                'message' => $e->getMessage(),
            ]);
        }

        $normalizedWrap = Thought::normalizeMetadataTags(['tags' => $tags]);
        $tags = array_values(array_unique($normalizedWrap['tags'] ?? $tags));

        $embedding = null;
        try {
            $forEmbed = Str::limit($body, 24000, '');
            $embedding = $this->openRouter->embed($forEmbed);
        } catch (\Throwable $e) {
            Log::warning('VideoResearchService: embed failed for video research thought.', [
                'video_thought_id' => $videoRoot->id,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'tags' => $tags,
            'people' => array_values($people),
            'action_items' => array_values($actionItems),
            'embedding' => $embedding,
        ];
    }

    /**
     * @param  array{status: string, source: string}  $transcriptState
     */
    private function normalizeSavedMarkdown(string $markdown, bool $transcriptContextAvailable, array $transcriptState): string
    {
        $sections = $this->extractCanonicalSections($markdown);
        $summaryPrefix = trim($sections['_preface'] ?? '');
        unset($sections['_preface']);

        if ($summaryPrefix !== '') {
            $sections[self::HEADING_SUMMARY] = $summaryPrefix
                .($sections[self::HEADING_SUMMARY] !== '' ? "\n\n".$sections[self::HEADING_SUMMARY] : '');
        }

        if (! $transcriptContextAvailable) {
            $limitedContextNote = sprintf(
                "Transcript context was unavailable or limited when this research ran.\n- transcript_status: %s\n- transcript_source: %s",
                $transcriptState['status'] !== '' ? $transcriptState['status'] : 'unknown',
                $transcriptState['source'] !== '' ? $transcriptState['source'] : 'unknown',
            );

            if (! str_contains($sections[self::HEADING_SOURCE_NOTES], 'Transcript context was unavailable or limited when this research ran.')) {
                $sections[self::HEADING_SOURCE_NOTES] = trim($sections[self::HEADING_SOURCE_NOTES]);
                $sections[self::HEADING_SOURCE_NOTES] = $sections[self::HEADING_SOURCE_NOTES] !== ''
                    ? $sections[self::HEADING_SOURCE_NOTES]."\n\n".$limitedContextNote
                    : $limitedContextNote;
            }
        }

        $parts = [];
        foreach (self::REQUIRED_SECTION_HEADINGS as $heading) {
            $content = trim($sections[$heading] ?? '');
            if ($content === '') {
                $content = '_No content._';
            }

            $parts[] = $heading."\n\n".$content;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return array<string, string>
     */
    private function extractCanonicalSections(string $markdown): array
    {
        $sections = array_fill_keys(self::REQUIRED_SECTION_HEADINGS, '');
        $sections['_preface'] = '';
        $matches = [];
        preg_match_all('/^## (Summary|Key Points|Positives|Negatives|Source Notes)\s*$/m', $markdown, $matches, PREG_OFFSET_CAPTURE);

        if (($matches[0] ?? []) === []) {
            $sections['_preface'] = trim($markdown);

            return $sections;
        }

        $firstOffset = (int) $matches[0][0][1];
        $sections['_preface'] = trim(substr($markdown, 0, $firstOffset));

        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $heading = '## '.$matches[1][$i][0];
            $start = (int) $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end = $i + 1 < $count ? (int) $matches[0][$i + 1][1] : strlen($markdown);
            $content = trim(substr($markdown, $start, $end - $start));
            $sections[$heading] = $sections[$heading] !== '' && $content !== ''
                ? $sections[$heading]."\n\n".$content
                : ($sections[$heading] !== '' ? $sections[$heading] : $content);
        }

        return $sections;
    }
}
