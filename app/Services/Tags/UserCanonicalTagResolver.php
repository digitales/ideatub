<?php

namespace App\Services\Tags;

use App\Models\Thought;
use App\Support\TagSlug;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a stream URL slug to the canonical tag string stored on thoughts (metadata.tags).
 *
 * Tag strings are collected in thought id order (then tag position). The first tag string that
 * maps to a given slug wins, matching legacy foreach semantics.
 */
final class UserCanonicalTagResolver
{
    /**
     * @param  list<string>  $tagSlugs
     * @return array<string, string|null> keyed by each requested slug (same string as provided)
     */
    public function resolveMany(int $userId, array $tagSlugs): array
    {
        if ($tagSlugs === []) {
            return [];
        }

        $map = $this->buildSlugToCanonicalMap($userId);
        $out = [];
        foreach ($tagSlugs as $slug) {
            $out[$slug] = $map[$slug] ?? null;
        }

        return $out;
    }

    public function resolve(int $userId, string $tagSlug): ?string
    {
        return $this->resolveMany($userId, [$tagSlug])[$tagSlug] ?? null;
    }

    /**
     * @return array<string, string> slug (normalized) => first matching canonical tag string
     */
    private function buildSlugToCanonicalMap(int $userId): array
    {
        $map = [];

        foreach ($this->iterateTagStringsForUser($userId) as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }

            $slug = TagSlug::from($tag);
            if ($slug === '') {
                continue;
            }

            if (! isset($map[$slug])) {
                $map[$slug] = $tag;
            }
        }

        return $map;
    }

    /**
     * Ordered tag strings as stored on thoughts (cross-thought order by thought id).
     *
     * @return Generator<int, string>
     */
    private function iterateTagStringsForUser(int $userId): Generator
    {
        $driver = Thought::query()->getConnection()->getDriverName();

        yield from match ($driver) {
            'sqlite' => $this->iterateTagStringsSqlite($userId),
            'pgsql' => $this->iterateTagStringsPgsql($userId),
            default => $this->iterateTagStringsFromMetadata($userId),
        };
    }

    /**
     * @return Generator<int, string>
     */
    private function iterateTagStringsSqlite(int $userId): Generator
    {
        $sql = <<<'SQL'
SELECT je.value AS tag
FROM thoughts AS t
CROSS JOIN json_each(
  CASE json_type(json_extract(t.metadata, '$.tags'))
    WHEN 'array' THEN json_extract(t.metadata, '$.tags')
    ELSE json('[]')
  END
) AS je
WHERE t.user_id = ?
  AND (
    t.metadata IS NULL
    OR json_valid(t.metadata)
  )
ORDER BY t.id, je.key
SQL;

        $rows = DB::select($sql, [$userId]);

        foreach ($rows as $row) {
            if (isset($row->tag) && $row->tag !== null && $row->tag !== '') {
                yield (string) $row->tag;
            }
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function iterateTagStringsPgsql(int $userId): Generator
    {
        $sql = <<<'SQL'
SELECT tag_text.value AS tag
FROM thoughts AS t
CROSS JOIN LATERAL jsonb_array_elements_text(
  CASE
    WHEN t.metadata IS NULL THEN '[]'::jsonb
    WHEN jsonb_typeof(COALESCE(t.metadata::jsonb, '{}'::jsonb) -> 'tags') = 'array'
      THEN COALESCE(t.metadata::jsonb, '{}'::jsonb) -> 'tags'
    ELSE '[]'::jsonb
  END
) WITH ORDINALITY AS tag_text(value, ord)
WHERE t.user_id = ?
ORDER BY t.id, tag_text.ord
SQL;

        $rows = DB::select($sql, [$userId]);

        foreach ($rows as $row) {
            if (isset($row->tag) && $row->tag !== null && $row->tag !== '') {
                yield (string) $row->tag;
            }
        }
    }

    /**
     * Fallback for unsupported drivers: load metadata JSON (same rows as legacy resolver).
     *
     * @return Generator<int, string>
     */
    private function iterateTagStringsFromMetadata(int $userId): Generator
    {
        $thoughts = Thought::query()
            ->where('user_id', $userId)
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->get();

        foreach ($thoughts as $thought) {
            $tags = data_get($thought->metadata, 'tags');
            if (! is_array($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                yield (string) $tag;
            }
        }
    }
}
