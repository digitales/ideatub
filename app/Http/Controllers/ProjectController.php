<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\Thought;
use Illuminate\Http\RedirectResponse;
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

        return view('projects.create', ['project' => new Project]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = Project::create([
            'user_id' => $request->user()->id,
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
        ]);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Project created.');
    }

    public function show(Project $project): View
    {
        $this->authorize('view', $project);

        $project->load(['thoughts' => function ($q) {
            $q->orderByPivot('sort_order');
        }]);

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

        return view('projects.edit', ['project' => $project]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update([
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
        ]);

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
}
