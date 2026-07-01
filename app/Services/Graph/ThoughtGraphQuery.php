<?php

namespace App\Services\Graph;

use App\Enums\ThoughtGraphMode;

final class ThoughtGraphQuery
{
    /**
     * @param  list<string>  $linkTypes
     * @param  list<string>  $layers
     */
    public function __construct(
        public ThoughtGraphMode $mode,
        public int $userId,
        public ?string $focalThoughtId = null,
        public ?string $projectId = null,
        public ?string $tag = null,
        public int $depth = 1,
        public bool $includeParentChild = true,
        public bool $includeChunks = false,
        public bool $includeNeighbors = false,
        public bool $includeSemantic = false,
        public bool $includeLinksAmongSemantic = true,
        public int $semanticK = 8,
        public float $maxDistance = 0.45,
        public array $linkTypes = [],
        public array $layers = ['thought_link'],
        public ?string $source = null,
        public ?string $since = null,
        public ?string $until = null,
        public int $limit = 200,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function forLocal(int $userId, string $focalId, array $input): self
    {
        return new self(
            mode: ThoughtGraphMode::Local,
            userId: $userId,
            focalThoughtId: $focalId,
            depth: max(1, min(2, (int) ($input['depth'] ?? 1))),
            includeParentChild: self::boolParam($input, 'include_parent_child', true),
            includeChunks: self::boolParam($input, 'include_chunks', false),
            includeSemantic: self::boolParam($input, 'include_semantic', false),
            semanticK: max(1, (int) ($input['semantic_k'] ?? 8)),
            maxDistance: (float) ($input['max_distance'] ?? 0.45),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function forProject(int $userId, string $projectId, array $input): self
    {
        $linkTypes = $input['link_types'] ?? [];
        if (! is_array($linkTypes)) {
            $linkTypes = [];
        }

        return new self(
            mode: ThoughtGraphMode::Project,
            userId: $userId,
            projectId: $projectId,
            includeParentChild: self::boolParam($input, 'include_parent_child', false),
            includeChunks: self::boolParam($input, 'include_chunks', false),
            includeNeighbors: self::boolParam($input, 'include_neighbors', false),
            includeSemantic: self::boolParam($input, 'include_semantic', false),
            semanticK: max(1, (int) ($input['semantic_k'] ?? 3)),
            maxDistance: (float) ($input['max_distance'] ?? 0.45),
            linkTypes: array_values(array_map('strval', $linkTypes)),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function forTag(int $userId, array $input): self
    {
        return new self(
            mode: ThoughtGraphMode::Tag,
            userId: $userId,
            tag: isset($input['tag']) ? trim((string) $input['tag']) : null,
            includeSemantic: self::boolParam($input, 'include_semantic', false),
            semanticK: max(1, (int) ($input['semantic_k'] ?? 3)),
            maxDistance: (float) ($input['max_distance'] ?? 0.45),
            since: isset($input['since']) ? (string) $input['since'] : null,
            limit: min(100, max(1, (int) ($input['limit'] ?? 80))),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function forSemantic(int $userId, string $focalId, array $input): self
    {
        return new self(
            mode: ThoughtGraphMode::Semantic,
            userId: $userId,
            focalThoughtId: $focalId,
            includeLinksAmongSemantic: self::boolParam($input, 'include_links', true),
            semanticK: max(1, (int) ($input['k'] ?? 12)),
            maxDistance: (float) ($input['max_distance'] ?? 0.45),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function forVault(int $userId, array $input): self
    {
        $layers = $input['layers'] ?? ['thought_link'];
        if (! is_array($layers)) {
            $layers = ['thought_link'];
        }

        return new self(
            mode: ThoughtGraphMode::Vault,
            userId: $userId,
            projectId: isset($input['project_id']) ? (string) $input['project_id'] : null,
            tag: isset($input['tag']) ? trim((string) $input['tag']) : null,
            includeChunks: self::boolParam($input, 'include_chunks', false),
            includeNeighbors: self::boolParam($input, 'include_neighbors', false),
            semanticK: max(1, (int) ($input['semantic_k'] ?? 2)),
            maxDistance: (float) ($input['max_distance'] ?? 0.45),
            layers: array_values(array_map('strval', $layers)),
            source: isset($input['source']) ? (string) $input['source'] : null,
            since: isset($input['since']) ? (string) $input['since'] : null,
            until: isset($input['until']) ? (string) $input['until'] : null,
            limit: min(200, max(1, (int) ($input['limit'] ?? 200))),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function boolParam(array $input, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $input)) {
            return $default;
        }

        return filter_var($input[$key], FILTER_VALIDATE_BOOL);
    }
}
