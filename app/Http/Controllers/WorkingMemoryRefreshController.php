<?php

namespace App\Http\Controllers;

use App\Jobs\ConsolidateWorkingMemory;
use App\Models\Project;
use App\Services\Tags\UserCanonicalTagResolver;
use App\Services\WorkingMemory\WorkingMemoryExternalGuard;
use App\Services\WorkingMemory\WorkingMemoryScopeNormalizer;
use App\Support\TagSlug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkingMemoryRefreshController extends Controller
{
    private const SKIPPED_MESSAGE = 'Working memory is synced from your agent. Re-run your agent sync, or use Rebuild in IdeaTub to replace it.';

    public function refreshGlobal(Request $request): RedirectResponse
    {
        if (! $this->dispatchConsolidated($request, 'global', 'global')) {
            return back()->with('info', self::SKIPPED_MESSAGE);
        }

        return back()->with('success', 'Queued consolidated rebuild for global working memory.');
    }

    public function refreshProject(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

        if (! $this->dispatchConsolidated($request, 'project', (string) $project->getKey())) {
            return back()->with('info', self::SKIPPED_MESSAGE);
        }

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

        if (! $this->dispatchConsolidated($request, 'tag', $scopeKey)) {
            return back()->with('info', self::SKIPPED_MESSAGE);
        }

        return back()->with('success', 'Queued consolidated rebuild for tag working memory.');
    }

    private function dispatchConsolidated(Request $request, string $scopeType, string $scopeKey): bool
    {
        [$normalizedType, $normalizedKey] = app(WorkingMemoryScopeNormalizer::class)->normalize($scopeType, $scopeKey);
        $force = $request->boolean('force');

        if (app(WorkingMemoryExternalGuard::class)->shouldSkipConsolidatedBuild(
            (int) $request->user()->id,
            $normalizedType,
            $normalizedKey,
            $force,
        )) {
            return false;
        }

        ConsolidateWorkingMemory::dispatch(
            (int) $request->user()->id,
            $normalizedType,
            $normalizedKey,
            force: $force,
        );

        return true;
    }
}
