<?php

namespace App\Http\Controllers;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Support\Comments\ShareContext;
use App\Support\Research\MicrositePageLabel;
use App\Support\Research\MicrositeSharedUrlHelper;
use App\Support\SafeCommonMarkConverter;
use App\Support\ThoughtTypeNavigation;
use App\View\Presenters\Comments\ResearchCommentsPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
class SharedResearchViewController extends Controller
{
    public function show(Request $request, string $token): View|RedirectResponse|Response
    {
        return $this->resolveShared($request, $token, null);
    }

    public function showPage(Request $request, string $token, string $page): View|RedirectResponse|Response
    {
        return $this->resolveShared($request, $token, $page);
    }

    private function resolveShared(Request $request, string $token, ?string $pagePath): View|RedirectResponse|Response
    {
        $share = ResearchShare::where('token', $token)->first();
        if ($share === null) {
            abort(404, 'Link not found or no longer available.');
        }
        if ($share->isExpired()) {
            abort(410, 'This link has expired.');
        }
        $formAction = $pagePath !== null
            ? route('shared-research.page', ['token' => $token, 'page' => $pagePath], true)
            : route('shared-research.show', $token, true);
        if ($share->password_hash !== null) {
            return $this->handlePasswordProtectedShare($request, $share, $formAction, $pagePath);
        }

        return $this->renderReadonly($share, $pagePath);
    }

    private function handlePasswordProtectedShare(
        Request $request,
        ResearchShare $share,
        string $formActionUrl,
        ?string $pagePath,
    ): View|RedirectResponse|Response {
        $token = $share->token;
        $cookieName = 'research_share_'.$token;

        if ($request->isMethod('post')) {
            $password = $request->input('password');
            if ($password !== null && Hash::check($password, $share->password_hash)) {
                Cookie::queue($cookieName, $token, 60 * 24, '/', null, null, true);

                return redirect()->to($formActionUrl);
            }

            return response()
                ->view('shared_research.password_form', [
                    'token' => $token,
                    'formAction' => $formActionUrl,
                    'error' => 'Incorrect password',
                ], 401);
        }

        $cookieValue = $request->cookie($cookieName);
        if ($cookieValue === $token) {
            return $this->renderReadonly($share, $pagePath);
        }

        return view('shared_research.password_form', [
            'token' => $token,
            'formAction' => $formActionUrl,
        ]);
    }

    /**
     * @param  ?string  $pagePathSegment  microsite page segment; null = home
     */
    private function renderReadonly(ResearchShare $share, ?string $pagePathSegment = null): View
    {
        $root = $share->thought;
        if ($root === null) {
            abort(404, 'Link not found or no longer available.');
        }
        if ($root->isMicrositeRoot()) {
            return $this->renderReadonlyMicrosite($share, $root, $pagePathSegment);
        }

        $sections = $root->childThoughts()->orderBy('created_at')->get();
        $converter = SafeCommonMarkConverter::make();
        $rootHtml = $converter->convert($root->content)->getContent();
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
            researchThoughtId: $root->id,
            shareId: $share->id,
            allowComments: (bool) $share->allow_comments,
        );
        $commentsPresenter = new ResearchCommentsPresenter($root, null, $shareContext);
        $guestDisabled = $commentsPresenter->allowGuestComments() ? null : 'Comments are disabled on this share.';
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
            $root,
            $commentFormAction,
            'guest',
            false,
            'Comments',
            $guestDisabled,
        );

        return view('shared_research.readonly', [
            'root' => $root,
            'root_html' => $rootHtml,
            'sharedBy' => $share->user,
            'documentTypeLabel' => $this->documentTypeLabelForSharedView($root),
            'share' => $share,
            'sharedResearchSectionComments' => $sharedResearchSectionComments,
            'pageThreadInclude' => $pageThreadInclude,
            'isMicrosite' => false,
            'openIdeaTubUrl' => url('/'),
        ]);
    }

    private function renderReadonlyMicrosite(ResearchShare $share, Thought $root, ?string $pagePathSegment): View
    {
        $active = $root->findMicrositePageByPathSegment($pagePathSegment);
        if ($active === null) {
            abort(404, 'Page not found in this site.');
        }
        $share->load('user');
        $converter = SafeCommonMarkConverter::make();
        $html = $converter->convert($active->content)->getContent();
        $html = MicrositeSharedUrlHelper::rewriteInAppQueryLinksInHtmlForShare($html, $share->token);
        $pages = collect([$root])->merge($root->childThoughtsForMicrosite()->get());
        $micrositeNav = $this->buildMicrositeNav($share, $pages, $active);
        $commentFormAction = route('shared-research.comment', $share->token);
        $shareContext = new ShareContext(
            researchThoughtId: $root->id,
            shareId: $share->id,
            allowComments: (bool) $share->allow_comments,
        );
        $commentsPresenter = new ResearchCommentsPresenter($root, null, $shareContext);
        $guestDisabled = $commentsPresenter->allowGuestComments() ? null : 'Comments are disabled on this share.';
        $pageThreadInclude = $commentsPresenter->threadIncludeForSection(
            $active,
            $commentFormAction,
            'guest',
            false,
            'Comments on this page',
            $guestDisabled,
        );
        $openIdeaTubUrl = route('idea.research.show', $root, true);
        if ((string) $active->id !== (string) $root->id) {
            $s = (string) data_get($active->source_metadata, 'page_path_segment', '');
            if ($s !== '') {
                $openIdeaTubUrl = route('idea.research.page', [
                    'thought' => $root,
                    'page' => $s,
                ], true);
            }
        }

        return view('shared_research.readonly', [
            'root' => $root,
            'activeMicrositePage' => $active,
            'root_html' => $html,
            'sharedBy' => $share->user,
            'documentTypeLabel' => $this->documentTypeLabelForSharedView($root),
            'share' => $share,
            'sharedResearchSectionComments' => collect(),
            'pageThreadInclude' => $pageThreadInclude,
            'isMicrosite' => true,
            'micrositeNav' => $micrositeNav,
            'openIdeaTubUrl' => $openIdeaTubUrl,
        ]);
    }

    /**
     * @param  Collection<int, Thought>  $allPages
     * @return Collection<int, object{url: string, label: string, is_active: bool}>
     */
    private function buildMicrositeNav(ResearchShare $share, Collection $allPages, Thought $active): Collection
    {
        return $allPages->map(function (Thought $t) use ($share, $active) {
            $seg = (string) data_get($t->source_metadata, 'page_path_segment', '');
            $isActive = (string) $t->id === (string) $active->id;
            if ($t->isMicrositeRoot() || $seg === '') {
                $url = route('shared-research.show', $share->token, true);
            } else {
                $url = route('shared-research.page', [
                    'token' => $share->token,
                    'page' => $seg,
                ], true);
            }

            return (object) [
                'url' => $url,
                'label' => MicrositePageLabel::forThought($t),
                'is_active' => $isActive,
            ];
        })->values();
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
