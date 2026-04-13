<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Thought;
use App\Services\ProjectMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThoughtProjectController extends Controller
{
    public function store(Request $request, Thought $thought, ProjectMembershipService $membership): RedirectResponse
    {
        $this->authorize('view', $thought);

        $validated = $request->validate([
            'project_id' => [
                'required',
                'uuid',
                Rule::exists('projects', 'id')->where('user_id', $request->user()->id),
            ],
        ]);

        $project = Project::query()->findOrFail($validated['project_id']);

        $this->authorize('update', $project);

        $membership->addThought($project, $thought);

        return back()->with('success', 'Added to project.');
    }

    public function destroy(Thought $thought, Project $project, ProjectMembershipService $membership): RedirectResponse
    {
        $this->authorize('view', $thought);
        $this->authorize('update', $project);

        $membership->removeThought($project, $thought);

        return back()->with('success', 'Removed from project.');
    }
}
