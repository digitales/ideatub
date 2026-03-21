<?php

namespace App\Services;

use App\Models\Thought;

/**
 * Single place to create a thought or chunked thoughts (root + sections).
 * All entry points (web, MCP capture_thought/capture_plan, API, email) should use this
 * so chunking (>500 words, split at headings) and no_chunking opt-out are consistent.
 */
class ThoughtCaptureService
{
    public function __construct(
        private OpenRouterService $openRouter,
        private ThoughtChunkingService $chunkingService
    ) {}

    /**
     * Create one thought or chunked thoughts. When parent_id is set, always creates a single thought.
     * Otherwise chunks when content has >500 words and no_chunking is not set.
     *
     * @param  array{content: string, user_id: int, parent_id?: string|null, source: string, source_metadata?: array|null, no_chunking?: bool, plan_slug?: string|null, doc_type?: string|null, file_path?: string|null, project?: string|null, extra_tags?: array, idea_metadata?: array}  $options
     * @return array{thought?: Thought, root?: Thought, chunked: bool, section_ids?: array<int, string>, count?: int}
     */
    public function create(array $options): array
    {
        $content = trim((string) ($options['content'] ?? ''));
        if ($content === '') {
            throw new \InvalidArgumentException('Content is required.');
        }

        $userId = (int) ($options['user_id'] ?? 0);
        if ($userId < 1) {
            throw new \InvalidArgumentException('user_id is required.');
        }

        $parentId = isset($options['parent_id']) && $options['parent_id'] !== '' ? (string) $options['parent_id'] : null;
        $source = isset($options['source']) && trim((string) $options['source']) !== ''
            ? mb_substr(trim((string) $options['source']), 0, 64)
            : 'api';
        $sourceMetadata = isset($options['source_metadata']) && is_array($options['source_metadata'])
            ? $options['source_metadata']
            : null;
        $noChunking = ! empty($options['no_chunking']);
        $planSlug = isset($options['plan_slug']) && trim((string) $options['plan_slug']) !== ''
            ? mb_substr(trim((string) $options['plan_slug']), 0, 128)
            : null;
        $docType = isset($options['doc_type']) && trim((string) $options['doc_type']) !== ''
            ? mb_substr(trim((string) $options['doc_type']), 0, 64)
            : null;
        $filePath = isset($options['file_path']) && trim((string) $options['file_path']) !== ''
            ? mb_substr(trim((string) $options['file_path']), 0, 512)
            : null;
        $project = isset($options['project']) && trim((string) $options['project']) !== ''
            ? mb_substr(trim((string) $options['project']), 0, 256)
            : null;
        $extraTags = isset($options['extra_tags']) && is_array($options['extra_tags'])
            ? array_values(array_filter(array_map(fn ($t) => is_string($t) ? trim($t) : '', $options['extra_tags'])))
            : [];
        $ideaMetadata = isset($options['idea_metadata']) && is_array($options['idea_metadata'])
            ? $options['idea_metadata']
            : null;

        $parent = null;
        if ($parentId !== null) {
            $parent = Thought::find($parentId);
            if ($parent === null) {
                throw new \InvalidArgumentException('Parent thought not found.');
            }
            if ((int) $parent->user_id !== $userId) {
                throw new \InvalidArgumentException('Parent thought does not belong to you.');
            }
        }

        // Replies are never chunked.
        $chunkOptions = [
            'no_chunking' => $noChunking,
            'no-chunking' => $noChunking,
            'source_metadata' => $sourceMetadata ?? [],
        ];
        $shouldChunk = $parent === null && $this->chunkingService->shouldChunk($content, $chunkOptions);

        if ($shouldChunk) {
            return $this->createChunked($content, $userId, $source, $sourceMetadata, $planSlug, $docType, $filePath, $project, $extraTags, $ideaMetadata);
        }

        if ($parent === null) {
            $sourceMetadata = $this->mergeDocumentHintsIntoSourceMetadata($sourceMetadata, $filePath, $project);
        }

        $thought = $this->createOne($content, $userId, $parent?->id, $source, $sourceMetadata, $planSlug, $docType, $extraTags, $ideaMetadata);

        return [
            'thought' => $thought,
            'chunked' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $sourceMetadata
     * @param  array<int, string>  $extraTags
     * @param  array<string, mixed>|null  $ideaMetadata
     */
    private function createOne(
        string $content,
        int $userId,
        ?string $parentId,
        string $source,
        ?array $sourceMetadata,
        ?string $planSlug,
        ?string $docType,
        array $extraTags,
        ?array $ideaMetadata = null,
    ): Thought {
        $embedding = $this->openRouter->embed($content);
        $metadata = Thought::normalizeMetadataTags($this->openRouter->extractMetadata($content));
        $tags = isset($metadata['tags']) && is_array($metadata['tags']) ? $metadata['tags'] : [];
        if ($planSlug !== null && $docType !== null) {
            $docTag = $docType.':'.mb_strtolower($planSlug);
            if (! in_array($docTag, $tags, true)) {
                $tags[] = $docTag;
            }
        }
        foreach ($extraTags as $t) {
            if ($t !== '' && ! in_array($t, $tags, true)) {
                $tags[] = $t;
            }
        }
        $metadata['tags'] = array_values(array_unique($tags));
        if ($ideaMetadata !== null && $ideaMetadata !== []) {
            $metadata = array_merge($metadata, $ideaMetadata);
        }

        $payload = [
            'content' => $content,
            'embedding' => $embedding,
            'metadata' => $metadata,
            'user_id' => $userId,
            'source' => $source,
            'source_metadata' => $sourceMetadata,
            'parent_id' => $parentId,
        ];

        return Thought::create($payload);
    }

    /**
     * @param  array<string, mixed>|null  $baseSourceMetadata
     * @param  array<int, string>  $extraTags
     * @param  array<string, mixed>|null  $ideaMetadata
     * @return array{root: Thought, chunked: true, section_ids: array<int, string>, count: int}
     */
    private function createChunked(
        string $content,
        int $userId,
        string $source,
        ?array $baseSourceMetadata,
        ?string $planSlug,
        ?string $docType,
        ?string $filePath,
        ?string $project,
        array $extraTags,
        ?array $ideaMetadata = null,
    ): array {
        $sections = $this->chunkingService->splitAtHeadings($content);
        $metadata = Thought::normalizeMetadataTags($this->openRouter->extractMetadata($content));
        $tags = isset($metadata['tags']) && is_array($metadata['tags']) ? $metadata['tags'] : [];
        if ($planSlug !== null && $docType !== null) {
            $docTag = $docType.':'.mb_strtolower($planSlug);
            if (! in_array($docTag, $tags, true)) {
                $tags[] = $docTag;
            }
        }
        foreach ($extraTags as $t) {
            if ($t !== '' && ! in_array($t, $tags, true)) {
                $tags[] = $t;
            }
        }
        $metadata['tags'] = array_values(array_unique($tags));
        if ($ideaMetadata !== null && $ideaMetadata !== []) {
            $metadata = array_merge($metadata, $ideaMetadata);
        }

        $base = array_filter([
            'doc_type' => $docType,
            'file_path' => $filePath,
            'plan_slug' => $planSlug,
            'project' => $project,
        ], fn ($v) => $v !== null && $v !== '');
        $baseSource = is_array($baseSourceMetadata) ? array_merge($baseSourceMetadata, $base) : $base;

        $rootContent = $sections[0]['content'];
        $rootMeta = array_merge($baseSource, [
            'section_title' => $sections[0]['title'],
            'section_index' => 0,
        ]);
        $rootEmbedding = $this->openRouter->embed($rootContent);
        $root = Thought::create([
            'content' => $rootContent,
            'embedding' => $rootEmbedding,
            'metadata' => $metadata,
            'user_id' => $userId,
            'source' => $source,
            'source_metadata' => $rootMeta,
            'parent_id' => null,
        ]);
        $sectionIds = [$root->id];

        foreach (array_slice($sections, 1) as $index => $section) {
            $sectionContent = $section['content'];
            $sectionEmbedding = $this->openRouter->embed($sectionContent);
            $sectionMeta = array_merge($baseSource, [
                'section_title' => $section['title'],
                'section_index' => $index + 1,
            ]);
            $child = Thought::create([
                'content' => $sectionContent,
                'embedding' => $sectionEmbedding,
                'metadata' => $metadata,
                'user_id' => $userId,
                'source' => $source,
                'source_metadata' => $sectionMeta,
                'parent_id' => $root->id,
            ]);
            $sectionIds[] = $child->id;
        }

        return [
            'root' => $root,
            'chunked' => true,
            'section_ids' => $sectionIds,
            'count' => count($sectionIds),
        ];
    }

    /**
     * Persist capture_plan-style file/project hints on single-thought captures (non-chunked roots).
     *
     * @param  array<string, mixed>|null  $sourceMetadata
     * @return array<string, mixed>|null
     */
    private function mergeDocumentHintsIntoSourceMetadata(?array $sourceMetadata, ?string $filePath, ?string $project): ?array
    {
        if ($filePath === null && $project === null) {
            return $sourceMetadata;
        }

        $out = $sourceMetadata ?? [];
        if ($project !== null && $project !== '' && ! array_key_exists('project', $out)) {
            $out['project'] = mb_substr(trim((string) $project), 0, 256);
        }
        if ($filePath !== null && $filePath !== '' && ! array_key_exists('file_path', $out)) {
            $out['file_path'] = mb_substr(trim((string) $filePath), 0, 512);
        }

        return $out;
    }
}
