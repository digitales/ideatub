<?php

namespace App\Http\Controllers;

use App\Models\ProjectShare;
use App\Models\Thought;
use App\Support\SafeCommonMarkConverter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SharedProjectViewController extends Controller
{
    public function hub(Request $request, string $token): View|RedirectResponse|Response
    {
        $share = $this->resolveShareOrAbort($token);

        return $this->gateWithPassword($request, $share, fn () => $this->renderHub($share));
    }

    public function readAll(Request $request, string $token): View|RedirectResponse|Response
    {
        $share = $this->resolveShareOrAbort($token);

        return $this->gateWithPassword($request, $share, fn () => $this->renderReadAll($share));
    }

    public function thought(Request $request, string $token, string $thoughtId): View|RedirectResponse|Response
    {
        $share = $this->resolveShareOrAbort($token);

        return $this->gateWithPassword($request, $share, fn () => $this->renderThoughtReadonly($share, $thoughtId));
    }

    private function resolveShareOrAbort(string $token): ProjectShare
    {
        $share = ProjectShare::query()->where('token', $token)->with('project')->first();

        if ($share === null) {
            abort(404, 'Link not found or no longer available.');
        }

        if ($share->isExpired()) {
            abort(410, 'This link has expired.');
        }

        if ($share->project === null) {
            abort(404, 'Link not found or no longer available.');
        }

        return $share;
    }

    /**
     * @param  callable(): (View|Response)  $render
     */
    private function gateWithPassword(Request $request, ProjectShare $share, callable $render): View|RedirectResponse|Response
    {
        if ($share->password_hash === null) {
            return $render();
        }

        $cookieName = 'project_share_'.$share->token;

        if ($request->isMethod('post')) {
            $password = $request->input('password');
            if ($password !== null && Hash::check($password, $share->password_hash)) {
                Cookie::queue($cookieName, $share->token, 60 * 24, '/', null, null, true);

                return redirect()->to($request->url());
            }

            return response()
                ->view('shared_projects.password_form', [
                    'postUrl' => $request->url(),
                    'error' => 'Incorrect password',
                ], 401);
        }

        if ($request->cookie($cookieName) === $share->token) {
            return $render();
        }

        return view('shared_projects.password_form', [
            'postUrl' => $request->url(),
        ]);
    }

    private function renderHub(ProjectShare $share): View
    {
        $project = $share->project;
        $project->load(['thoughts' => fn ($q) => $project->orderMembersForDisplay($q)]);
        $share->load('user');

        $converter = SafeCommonMarkConverter::make();
        $descriptionHtml = $project->description
            ? $converter->convert($project->description)->getContent()
            : null;

        return view('shared_projects.hub', [
            'share' => $share,
            'project' => $project,
            'descriptionHtml' => $descriptionHtml,
            'sharedBy' => $share->user,
            'token' => $share->token,
        ]);
    }

    private function renderReadAll(ProjectShare $share): View
    {
        $project = $share->project;
        $thoughts = $project->orderMembersForDisplay($project->thoughts())->get();
        $converter = SafeCommonMarkConverter::make();

        $blocks = $thoughts->map(function (Thought $thought) use ($converter) {
            return (object) [
                'thought' => $thought,
                'content_html' => $converter->convert($thought->content)->getContent(),
            ];
        });

        $share->load('user');

        return view('shared_projects.read_all', [
            'share' => $share,
            'project' => $project,
            'blocks' => $blocks,
            'sharedBy' => $share->user,
            'token' => $share->token,
        ]);
    }

    private function renderThoughtReadonly(ProjectShare $share, string $thoughtId): View|Response
    {
        $project = $share->project;

        if (! $project->thoughts()->whereKey($thoughtId)->exists()) {
            abort(404, 'Not found.');
        }

        $thought = Thought::query()->find($thoughtId);
        if ($thought === null) {
            abort(404, 'Not found.');
        }

        $converter = SafeCommonMarkConverter::make();
        $contentHtml = $converter->convert($thought->content)->getContent();
        $share->load('user');

        return view('shared_projects.thought_readonly', [
            'share' => $share,
            'project' => $project,
            'thought' => $thought,
            'contentHtml' => $contentHtml,
            'sharedBy' => $share->user,
            'token' => $share->token,
        ]);
    }
}
