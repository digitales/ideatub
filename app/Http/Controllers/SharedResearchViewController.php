<?php

namespace App\Http\Controllers;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Support\Comments\ShareContext;
use App\Support\ThoughtTypeNavigation;
use App\View\Presenters\Comments\ResearchCommentsPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use League\CommonMark\CommonMarkConverter;

class SharedResearchViewController extends Controller
{
    /**
     * Show shared research (readonly). GET: show content or password form.
     * POST: submit password to unlock; on success set cookie and redirect to GET.
     */
    public function show(Request $request, string $token): View|RedirectResponse|Response
    {
        $share = ResearchShare::where('token', $token)->first();

        if ($share === null) {
            abort(404, 'Link not found or no longer available.');
        }

        if ($share->isExpired()) {
            abort(410, 'This link has expired.');
        }

        if ($share->password_hash !== null) {
            return $this->handlePasswordProtectedShare($request, $share);
        }

        return $this->renderReadonly($share);
    }

    /**
     * Handle password-protected share: POST = verify password and set cookie; GET = check cookie or show form.
     */
    private function handlePasswordProtectedShare(Request $request, ResearchShare $share): View|RedirectResponse|Response
    {
        $token = $share->token;
        $cookieName = 'research_share_'.$token;

        if ($request->isMethod('post')) {
            $password = $request->input('password');
            if ($password !== null && Hash::check($password, $share->password_hash)) {
                // Cookie is encrypted (and signed) by Laravel's EncryptCookies middleware by default.
                Cookie::queue($cookieName, $token, 60 * 24, '/', null, null, true);

                return redirect()->route('shared-research.show', ['token' => $token]);
            }

            return response()
                ->view('shared_research.password_form', ['token' => $token, 'error' => 'Incorrect password'], 401);
        }

        $cookieValue = $request->cookie($cookieName);
        if ($cookieValue === $token) {
            return $this->renderReadonly($share);
        }

        return view('shared_research.password_form', ['token' => $token]);
    }

    /**
     * Load thought and sections, return readonly view.
     * Renders content as markdown for readable headings, lists, code, etc.
     */
    private function renderReadonly(ResearchShare $share): View
    {
        $thought = $share->thought;

        if ($thought === null) {
            abort(404, 'Link not found or no longer available.');
        }

        $sections = $thought->childThoughts()->orderBy('created_at')->get();
        $converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);

        $rootHtml = $converter->convert($thought->content)->getContent();
        $sectionsWithHtml = $sections->map(function ($section) use ($converter) {
            return (object) [
                'id' => $section->id,
                'thought' => $section,
                'content_html' => $converter->convert($section->content)->getContent(),
                'created_at' => $section->created_at,
            ];
        });

        $share->load('user');

        $shareContext = new ShareContext(
            researchThoughtId: $thought->id,
            shareId: $share->id,
            allowComments: (bool) $share->allow_comments,
        );
        $commentsPresenter = new ResearchCommentsPresenter(
            $thought,
            null,
            $shareContext,
        );

        $guestDisabled = $commentsPresenter->allowGuestComments()
            ? null
            : 'Comments are disabled on this share.';
        $commentFormAction = route('shared-research.comment', $share->token);

        $sharedResearchSectionComments = $sectionsWithHtml->map(
            function (object $section) use ($commentsPresenter, $commentFormAction, $guestDisabled): array {
                $thought = $section->thought ?? null;
                if ($thought === null) {
                    return [
                        'id' => $section->id ?? null,
                        'content_html' => $section->content_html,
                        'details_thread_include' => null,
                        'comment_summary' => null,
                    ];
                }

                $detailsInclude = $commentsPresenter->threadIncludeForSection(
                    $thought,
                    $commentFormAction,
                    'guest',
                    false,
                    'Section comments',
                    $guestDisabled,
                );
                $count = count($detailsInclude['rows']);

                return [
                    'id' => $section->id ?? null,
                    'content_html' => $section->content_html,
                    'details_thread_include' => $detailsInclude,
                    'comment_summary' => [
                        'count' => $count,
                        'label' => Str::plural('comment', $count),
                    ],
                ];
            }
        );

        $pageThreadInclude = $commentsPresenter->threadIncludeForSection(
            $thought,
            $commentFormAction,
            'guest',
            false,
            'Comments',
            $guestDisabled,
        );

        return view('shared_research.readonly', [
            'root' => $thought,
            'root_html' => $rootHtml,
            'sharedBy' => $share->user,
            'documentTypeLabel' => $this->documentTypeLabelForSharedView($thought),
            'share' => $share,
            'sharedResearchSectionComments' => $sharedResearchSectionComments,
            'pageThreadInclude' => $pageThreadInclude,
        ]);
    }

    private function documentTypeLabelForSharedView(Thought $root): string
    {
        $typeRaw = data_get($root->metadata, 'type');
        if (! is_string($typeRaw)) {
            return 'Shared document';
        }
        $normalized = mb_strtolower(trim($typeRaw));
        if ($normalized === '') {
            return 'Shared document';
        }

        $extra = ['decision', 'dev', 'support', 'spec'];
        if (in_array($normalized, $extra, true)) {
            return ucfirst($normalized);
        }

        $navKey = ThoughtTypeNavigation::normalizeTypeKey($typeRaw);
        if (in_array($navKey, ['research', 'plan', 'meeting'], true)) {
            return ThoughtTypeNavigation::thoughtDisplayLabel($navKey);
        }

        return 'Shared document';
    }
}
