<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\Thought;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ProjectScopeMatcher
{
    /**
     * @param  Collection<int, string>|null  $childProjectIds  Preloaded child project IDs for client-root scopes (avoids N+1).
     */
    public function thoughtMatchesProjectScope(
        Thought $thought,
        string $scopeKey,
        ?Project $scopeProject,
        ?Collection $childProjectIds = null,
    ): bool {
        if ($scopeProject !== null) {
            if ($scopeProject->isElixirrClientRoot()) {
                return $this->matchesClientRoot($thought, $scopeProject, $childProjectIds);
            }

            if ($scopeProject->parent_project_id !== null) {
                return $this->matchesChildProject($thought, $scopeProject);
            }
        }

        return $this->matchesLegacySlugScope($thought, $scopeKey);
    }

    /**
     * @param  Collection<int, string>|null  $childProjectIds
     */
    private function matchesClientRoot(Thought $thought, Project $root, ?Collection $childProjectIds): bool
    {
        $clientSlug = Str::of((string) $root->elixirr_client_slug)->trim()->lower()->toString();
        if ($clientSlug === '') {
            return false;
        }

        if ($this->isLinkedToProject($thought, (string) $root->id)) {
            return true;
        }

        $childIds = $childProjectIds ?? $root->children()->pluck('id');
        foreach ($childIds as $childId) {
            if ($this->isLinkedToProject($thought, (string) $childId)) {
                return true;
            }
        }

        if ($this->hasClientTag($thought, $clientSlug)) {
            return true;
        }

        return $this->normalizedMetadataProject($thought) === $clientSlug;
    }

    private function matchesChildProject(Thought $thought, Project $child): bool
    {
        if ($this->isLinkedToProject($thought, (string) $child->id)) {
            return true;
        }

        $clientSlug = Str::of((string) $child->elixirr_client_slug)->trim()->lower()->toString();
        $projectSlug = Str::of((string) $child->elixirr_project_slug)->trim()->lower()->toString();
        if ($clientSlug === '' || $projectSlug === '') {
            return false;
        }

        $compositeKey = $clientSlug.'/'.$projectSlug;

        return $this->normalizedMetadataProject($thought) === $compositeKey;
    }

    private function matchesLegacySlugScope(Thought $thought, string $scopeKey): bool
    {
        $normalizedScopeKey = Str::of($scopeKey)->trim()->lower()->toString();
        if ($normalizedScopeKey === '') {
            return false;
        }

        $metadataProject = $this->normalizedMetadataProject($thought);
        if ($metadataProject !== '' && $metadataProject === $normalizedScopeKey) {
            return true;
        }

        return $this->isLinkedToProject($thought, $normalizedScopeKey);
    }

    private function normalizedMetadataProject(Thought $thought): string
    {
        return Str::of((string) data_get($thought->source_metadata, 'project'))
            ->trim()
            ->lower()
            ->toString();
    }

    private function hasClientTag(Thought $thought, string $clientSlug): bool
    {
        return $this->normalizedTags($thought)->containsStrict('client:'.$clientSlug);
    }

    /**
     * @return Collection<int, string>
     */
    private function normalizedTags(Thought $thought): Collection
    {
        return collect(data_get($thought->metadata, 'tags', []))
            ->map(fn ($tag): string => Str::of((string) $tag)->trim()->lower()->toString())
            ->filter(fn (string $tag): bool => $tag !== '')
            ->values();
    }

    private function isLinkedToProject(Thought $thought, string $projectId): bool
    {
        $projects = $thought->relationLoaded('projects')
            ? $thought->projects
            : $thought->projects()->get(['projects.id']);

        return $projects->contains(
            fn (Project $project): bool => (string) $project->id === $projectId
        );
    }
}
