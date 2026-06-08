<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Thought;
use App\Services\Projects\ProjectContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectContextController extends Controller
{
    public function store(Request $request, Project $project, ProjectContextService $context): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'thought_id' => [
                'required',
                'uuid',
                Rule::exists('thoughts', 'id')->where('user_id', $request->user()->id),
            ],
        ]);

        $thought = Thought::query()->findOrFail($validated['thought_id']);

        $context->pin($project, $thought);

        return back()->with('success', 'Pinned as project context.');
    }

    public function destroy(Project $project, ProjectContextService $context): RedirectResponse
    {
        $this->authorize('update', $project);

        $context->unpin($project);

        return back()->with('success', 'Unpinned project context.');
    }
}
