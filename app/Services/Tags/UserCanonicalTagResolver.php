<?php

namespace App\Services\Tags;

use App\Models\Thought;
use App\Support\TagSlug;

/**
 * Resolves a stream URL slug to the canonical tag string stored on thoughts (metadata.tags).
 */
final class UserCanonicalTagResolver
{
    public function resolve(int $userId, string $tagSlug): ?string
    {
        $tags = Thought::query()
            ->where('user_id', $userId)
            ->select('metadata')
            ->get()
            ->pluck('metadata')
            ->pluck('tags')
            ->flatten()
            ->unique()
            ->filter()
            ->values();

        foreach ($tags as $t) {
            if (TagSlug::from((string) $t) === $tagSlug) {
                return (string) $t;
            }
        }

        return null;
    }
}
