<?php

namespace App\Support\Comments;

use App\Models\Thought;

/**
 * Resolves the research document root for a thought that may be the root, a section, or unrelated.
 */
final class ResearchUnreadResearchRootResolver
{
    /**
     * Walk ancestors to the root thought; return it only if it is a canonical "research" root.
     */
    public static function researchRootForThought(Thought $thought): ?Thought
    {
        $current = $thought;
        for ($guard = 0; $guard < 100; $guard++) {
            if ($current->parent_id === null) {
                return Thought::query()
                    ->whereKey($current->id)
                    ->matchingCanonicalMetadataType('research')
                    ->first();
            }

            $parent = Thought::query()->find($current->parent_id);
            if ($parent === null) {
                return null;
            }
            $current = $parent;
        }

        return null;
    }

    /**
     * Whether comments on $commentable are included in research unread scope (root + direct children only).
     */
    public static function commentableIsInResearchUnreadTree(Thought $researchRoot, Thought $commentable): bool
    {
        if ($commentable->id === $researchRoot->id) {
            return true;
        }

        return $commentable->parent_id === $researchRoot->id;
    }
}
