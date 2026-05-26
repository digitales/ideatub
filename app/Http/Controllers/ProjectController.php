<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\Thought;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Project::class);

        $projects = Project::query()
            ->where('user_id', auth()->id())
            ->withCount([
                'thoughts as top_level_ideas_count' => function ($query) {
                    $query->whereNull('thoughts.parent_id');
                },
            ])
            ->orderByDesc('updated_at')
            ->get();

        return view('projects.index', ['projects' => $projects]);
    }

    public function create(): View
    {
        $this->authorize('create', Project::class);

        return view('projects.create', [
            'project' => new Project,
            'parentProjectOptions' => $this->parentProjectOptionsForUser(),
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = Project::create([
            'user_id' => $request->user()->id,
            ...$this->projectAttributesFromRequest($request),
        ]);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Project created.');
    }

    public function show(Project $project): View
    {
        $this->authorize('view', $project);

        $project->load(['thoughts' => fn ($q) => $project->orderMembersForDisplay($q)]);
        $project->thoughts->loadMissing('parent');

        $memberIds = $project->thoughts->pluck('id')->all();

        $thoughtOptions = Thought::query()
            ->where('user_id', auth()->id())
            ->whereNull('parent_id')
            ->when($memberIds !== [], fn ($q) => $q->whereNotIn('id', $memberIds))
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'content']);

        return view('projects.show', [
            'project' => $project,
            'thoughtOptions' => $thoughtOptions,
        ]);
    }

    public function edit(Project $project): View
    {
        $this->authorize('update', $project);

        return view('projects.edit', [
            'project' => $project,
            'parentProjectOptions' => $this->parentProjectOptionsForUser($project),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($this->projectAttributesFromRequest($request));

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()
            ->route('projects.index')
            ->with('success', 'Project archived.');
    }

    /**
     * @return array<string, mixed>
     */
    private function projectAttributesFromRequest(StoreProjectRequest|UpdateProjectRequest $request): array
    {
        return [
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            'elixirr_client_slug' => $request->validated('elixirr_client_slug'),
            'elixirr_project_slug' => $request->validated('elixirr_project_slug'),
            'parent_project_id' => $request->validated('parent_project_id'),
        ];
    }

    /**
     * @return Collection<int, Project>
     */
    private function parentProjectOptionsForUser(?Project $exclude = null): Collection
    {
        return Project::query()
            ->where('user_id', auth()->id())
            ->whereNull('parent_project_id')
            ->when($exclude !== null, fn ($query) => $query->where('id', '!=', $exclude->id))
            ->orderBy('title')
            ->get(['id', 'title', 'elixirr_client_slug']);
    }
}
