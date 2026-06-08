<?php

namespace App\Services\Projects;

use App\Models\Project;

final class ProjectListingService
{
    /**
     * @return array{data: list<array{
     *     id: string,
     *     title: string,
     *     elixirr_client_slug: ?string,
     *     elixirr_project_slug: ?string,
     *     parent_project_id: ?string,
     *     context_thought_id: ?string,
     *     working_memory_auto_update: bool,
     * }>}
     */
    public function forUser(int $userId, ?string $elixirrClientSlug, ?string $parentProjectId): array
    {
        $query = Project::query()
            ->where('user_id', $userId)
            ->orderBy('title');

        if ($elixirrClientSlug !== null) {
            $query->where('elixirr_client_slug', $elixirrClientSlug);
        }

        if ($parentProjectId !== null) {
            $query->where('parent_project_id', $parentProjectId);
        }

        $data = $query->get()->map(fn (Project $project) => [
            'id' => (string) $project->id,
            'title' => $project->title,
            'elixirr_client_slug' => $project->elixirr_client_slug,
            'elixirr_project_slug' => $project->elixirr_project_slug,
            'parent_project_id' => $project->parent_project_id !== null
                ? (string) $project->parent_project_id
                : null,
            'context_thought_id' => $project->context_thought_id !== null
                ? (string) $project->context_thought_id
                : null,
            'working_memory_auto_update' => (bool) $project->working_memory_auto_update,
        ])->values()->all();

        return ['data' => $data];
    }
}
