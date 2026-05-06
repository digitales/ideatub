<?php

namespace App\Services\WorkingMemory;

use App\Models\Thought;
use Illuminate\Support\Str;

class WorkingMemoryScopeResolver
{
    public function __construct(
        private readonly MemoryInsightsService $memoryInsightsService,
        private readonly ForcedTagResolver $forcedTagResolver,
    ) {}

    /**
     * @return array<int, array{scope_type: string, scope_key: string}>
     */
    public function forThought(Thought $thought): array
    {
        $scopes = [
            ['scope_type' => 'global', 'scope_key' => 'global'],
        ];

        $metadataProject = data_get($thought->source_metadata, 'project');
        if (is_string($metadataProject)) {
            $normalizedProject = Str::of($metadataProject)->trim()->lower()->toString();
            if ($normalizedProject !== '') {
                $scopes[] = ['scope_type' => 'project', 'scope_key' => $normalizedProject];
            }
        }

        foreach ($thought->projects()->pluck('projects.id') as $projectId) {
            $scopes[] = ['scope_type' => 'project', 'scope_key' => (string) $projectId];
        }

        $thoughtTags = $this->forcedTagResolver->normalizeTags(data_get($thought->metadata, 'tags'));
        foreach ($thoughtTags as $tag) {
            $scopes[] = ['scope_type' => 'tag', 'scope_key' => $tag];
        }

        $forcedTags = is_int($thought->user_id)
            ? $this->forcedTagResolver->forUserId($thought->user_id)
            : [];
        foreach (array_intersect($thoughtTags, $forcedTags) as $forcedTag) {
            $scopes[] = ['scope_type' => 'tag', 'scope_key' => $forcedTag];
        }

        if ($this->memoryInsightsService->isResearchThought($thought)) {
            $scopes[] = ['scope_type' => 'insights', 'scope_key' => 'global'];
        }

        $deduplicated = [];
        $seen = [];

        foreach ($scopes as $scope) {
            $signature = $scope['scope_type'].'|'.$scope['scope_key'];
            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $deduplicated[] = $scope;
        }

        return $deduplicated;
    }
}
