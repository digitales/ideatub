<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectShare;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ProjectShareController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        $shares = ProjectShare::query()
            ->where('project_id', $project->id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return view('project_shares.index', [
            'project' => $project,
            'shares' => $shares,
            'focusShareId' => $request->query('share'),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'password' => 'nullable|string|min:1',
            'expires_at' => 'nullable|date|after:now',
        ]);

        ProjectShare::create([
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
            'token' => ProjectShare::generateToken(),
            'password_hash' => $request->filled('password') ? Hash::make($request->password) : null,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return redirect()
            ->route('projects.shares.index', $project)
            ->with('success', 'Share link created. Copy below.');
    }

    public function update(Request $request, ProjectShare $projectShare): RedirectResponse
    {
        $this->authorize('update', $projectShare);

        $validated = $request->validate([
            'password' => 'nullable|string',
            'password_remove' => 'sometimes|boolean',
            'expires_at' => 'nullable|date',
        ]);

        $passwordChanged = false;

        if (! empty($validated['password_remove'] ?? false)) {
            $projectShare->password_hash = null;
            $passwordChanged = true;
        } elseif ($request->filled('password')) {
            $projectShare->password_hash = Hash::make($request->password);
            $passwordChanged = true;
        }

        if ($request->has('expires_at')) {
            $projectShare->expires_at = $request->filled('expires_at') ? $request->date('expires_at') : null;
        }

        $projectShare->save();

        $response = redirect()->back()->with('success', 'Share updated.');

        if ($passwordChanged) {
            $response = $response->withCookie(Cookie::forget('project_share_'.$projectShare->token));
        }

        return $response;
    }

    public function destroy(ProjectShare $projectShare): RedirectResponse
    {
        $this->authorize('delete', $projectShare);

        $token = $projectShare->token;
        $project = $projectShare->project;
        $projectShare->delete();

        return redirect()
            ->route('projects.shares.index', $project)
            ->withCookie(Cookie::forget('project_share_'.$token))
            ->with('success', 'Share revoked.');
    }
}
