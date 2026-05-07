<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Services\WorkingMemory\WorkingMemoryScopeResolver;

/**
 * Picks the single most-specific working-memory scope a meeting compaction
 * should land on.
 *
 * Shared between SynthesizeMeetingCompactionJob (where to write the compaction)
 * and the bootstrap / rebuild commands (which meetings belong to a requested
 * scope) so the two stay in lockstep.
 *
 * Preference order, mirroring WorkingMemoryScopeResolver::forThought():
 *   1. First `project` scope (typically derived from source_metadata.project)
 *   2. First `tag` scope (from metadata.tags or forced tags)
 *   3. global/global fallback
 */
class MeetingPrimaryScopeResolver
{
    public function __construct(
        private readonly WorkingMemoryScopeResolver $resolver,
    ) {}

    /**
     * @return array{0: string, 1: string}
     */
    public function forThought(Thought $thought): array
    {
        $scopes = $this->resolver->forThought($thought);

        foreach ($scopes as $scope) {
            if ($scope['scope_type'] === 'project') {
                return ['project', $scope['scope_key']];
            }
        }

        foreach ($scopes as $scope) {
            if ($scope['scope_type'] === 'tag') {
                return ['tag', $scope['scope_key']];
            }
        }

        return ['global', 'global'];
    }
}
