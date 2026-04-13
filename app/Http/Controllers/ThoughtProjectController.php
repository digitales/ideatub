<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Thought;
use App\Services\ProjectMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThoughtProjectController extends Controller
{
    /**
     * Attach a thought to a project. Use project_id = "__new__" with new_project_title (and optional
     * new_project_description) to create a project and attach in one request.
     */
    public function store(Request $request, Thought $thought, ProjectMembershipService $membership): RedirectResponse
    {
        $this->authorize('view', $thought);

        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'new_project_title' => ['prohibited_unless:project_id,__new__', 'required_if:project_id,__new__', 'nullable', 'string', 'max:255'],
            'new_project_description' => ['prohibited_unless:project_id,__new__', 'nullable', 'string', 'max:65535'],
        ]);

        if ($validated['project_id'] === '__new__') {
            $this->authorize('create', Project::class);

            return DB::transaction(function () use ($request, $thought, $membership, $validated): RedirectResponse {
                $project = Project::create([
                    'user_id' => $request->user()->id,
                    'title' => $validated['new_project_title'],
                    'description' => $validated['new_project_description'] ?? null,
                ]);
                $membership->addThought($project, $thought);

                return back()->with('success', 'Project created and thought added.');
            });
        }

        Validator::make(
            ['project_id' => $validated['project_id']],
            [
                'project_id' => [
                    'uuid',
                    Rule::exists('projects', 'id')->where('user_id', $request->user()->id),
                ],
            ]
        )->validate();

        $project = Project::query()->findOrFail($validated['project_id']);

        $this->authorize('update', $project);

        if ($project->thoughts()->whereKey($thought->id)->exists()) {
            throw ValidationException::withMessages([
                'project_id' => __('This thought is already in that project.'),
            ]);
        }

        DB::transaction(function () use ($membership, $project, $thought): void {
            $membership->addThought($project, $thought);
        });

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
