<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Thought;
use App\Services\ProjectMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectThoughtController extends Controller
{
    public function store(Request $request, Project $project, ProjectMembershipService $membership): RedirectResponse
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

        $membership->addThought($project, $thought);

        return back()->with('success', 'Thought added to project.');
    }

    public function destroy(Project $project, Thought $thought, ProjectMembershipService $membership): RedirectResponse
    {
        $this->authorize('update', $project);

        if ($thought->user_id !== auth()->id()) {
            abort(403);
        }

        $membership->removeThought($project, $thought);

        return back()->with('success', 'Thought removed from project.');
    }
}
