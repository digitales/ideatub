<?php

namespace App\Http\Controllers;

use App\Jobs\ConsolidateWorkingMemory;
use App\Models\Project;
use App\Services\Tags\UserCanonicalTagResolver;
use App\Services\WorkingMemory\WorkingMemoryScopeNormalizer;
use App\Support\TagSlug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkingMemoryRefreshController extends Controller
{
    public function refreshGlobal(Request $request): RedirectResponse
    {
        $this->dispatchConsolidated($request, 'global', 'global');

        return back()->with('success', 'Queued consolidated rebuild for global working memory.');
    }

    public function refreshProject(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

        $this->dispatchConsolidated($request, 'project', (string) $project->getKey());

        return back()->with('success', 'Queued consolidated rebuild for project working memory.');
    }

    public function refreshTag(Request $request): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return back()->with('error', 'Invalid tag context for working memory refresh.');
        }

        $signedTag = $request->query('tag');
        if (! is_string($signedTag)) {
            return back()->with('error', 'Invalid tag context for working memory refresh.');
        }

        $signedSlug = TagSlug::from($signedTag);
        if ($signedSlug === '' || Str::length($signedSlug) > 100) {
            return back()->with('error', 'Invalid tag context for working memory refresh.');
        }

        $postedTag = $request->input('tag');
        if (is_string($postedTag)) {
            $postedSlug = TagSlug::from($postedTag);
            if ($postedSlug === '' || $postedSlug !== $signedSlug) {
                return back()->with('error', 'Invalid tag context for working memory refresh.');
            }
        }

        $canonical = app(UserCanonicalTagResolver::class)->resolve((int) $request->user()->id, $signedSlug);
        $scopeKey = $canonical !== null
            ? Str::of($canonical)->trim()->lower()->toString()
            : Str::of($signedSlug)->trim()->lower()->toString();

        $this->dispatchConsolidated($request, 'tag', $scopeKey);

        return back()->with('success', 'Queued consolidated rebuild for tag working memory.');
    }

    private function dispatchConsolidated(Request $request, string $scopeType, string $scopeKey): void
    {
        [$normalizedType, $normalizedKey] = app(WorkingMemoryScopeNormalizer::class)->normalize($scopeType, $scopeKey);

        ConsolidateWorkingMemory::dispatch(
            (int) $request->user()->id,
            $normalizedType,
            $normalizedKey
        );
    }
}
