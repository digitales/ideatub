<?php

namespace App\Services\Attention;

use App\Models\Project;
use App\Models\WorkingMemory;
use App\Support\TagSlug;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class AttentionScopeResolver
{
    /**
     * @return array{title: string, href: string, project_id: string|null, project_title: string|null}
     */
    public function resolve(WorkingMemory $memory, Collection $projects): array
    {
        $scopeKey = (string) $memory->scope_key;
        $project = $memory->scope_type === 'project'
            ? $projects->get(Str::lower($scopeKey))
            : null;

        return [
            'title' => $this->titleFor($memory, $project, $scopeKey),
            'href' => $this->hrefFor($memory, $project, $scopeKey),
            'project_id' => $project !== null ? (string) $project->getKey() : null,
            'project_title' => $project?->title,
        ];
    }

    /**
     * @param  Collection<int, WorkingMemory>  $projectMemories
     * @return Collection<string, Project>
     */
    public function projectsFor(int $userId, Collection $projectMemories): Collection
    {
        $scopeKeys = $projectMemories
            ->pluck('scope_key')
            ->map(fn ($key): string => Str::lower((string) $key))
            ->filter()
            ->unique()
            ->values();

        if ($scopeKeys->isEmpty()) {
            return collect();
        }

        $uuidKeys = $scopeKeys->filter(fn (string $key): bool => Str::isUuid($key))->values();
        $slugKeys = $scopeKeys->reject(fn (string $key): bool => Str::isUuid($key))->values();

        $projects = collect();

        if ($uuidKeys->isNotEmpty()) {
            $projects = $projects->merge(
                Project::query()
                    ->where('user_id', $userId)
                    ->whereIn('id', $uuidKeys)
                    ->get()
            );
        }

        if ($slugKeys->isNotEmpty()) {
            $projects = $projects->merge(
                Project::query()
                    ->where('user_id', $userId)
                    ->get()
                    ->filter(function (Project $project) use ($slugKeys): bool {
                        $slug = Str::slug($project->title);

                        return $slug !== '' && $slugKeys->contains(Str::lower($slug));
                    })
            );
        }

        return $this->projectLookupMap($projects->unique(fn (Project $project): string => (string) $project->getKey()));
    }

    private function titleFor(WorkingMemory $memory, ?Project $project, string $scopeKey): string
    {
        return match ($memory->scope_type) {
            'global' => 'Global',
            'insights' => 'Insights',
            'project' => $project?->title ?? (
                Str::isUuid($scopeKey) ? 'Unavailable project' : $this->readableSlugTitle($scopeKey)
            ),
            'tag' => $this->readableSlugTitle($scopeKey),
            default => $scopeKey,
        };
    }

    private function hrefFor(WorkingMemory $memory, ?Project $project, string $scopeKey): string
    {
        return match ($memory->scope_type) {
            'global' => route('memory.show'),
            'insights' => Route::has('memory.insights') ? route('memory.insights') : route('memory.show'),
            'project' => $project !== null
                ? route('projects.memory.show', $project)
                : (Str::isUuid($scopeKey) ? route('projects.index') : route('memory.project-scope.show', ['scopeKey' => $scopeKey])),
            'tag' => route('memory.tag.show', ['tag' => TagSlug::from($scopeKey)]),
            default => route('memory.scopes.index'),
        };
    }

    private function readableSlugTitle(string $scopeKey): string
    {
        return Str::of($scopeKey)
            ->replace(['-', '_', '/'], ' ')
            ->squish()
            ->title()
            ->toString();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return Collection<string, Project>
     */
    private function projectLookupMap(Collection $projects): Collection
    {
        $map = collect();

        foreach ($projects as $project) {
            $map[Str::lower((string) $project->getKey())] = $project;

            $slug = Str::slug($project->title);
            if ($slug !== '') {
                $map[Str::lower($slug)] = $project;
            }
        }

        return $map;
    }
}
