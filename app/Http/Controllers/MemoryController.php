<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Tags\UserCanonicalTagResolver;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use App\Support\TagSlug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MemoryController extends Controller
{
    public function __construct(
        private readonly WorkingMemoryAssembler $workingMemoryAssembler,
        private readonly UserCanonicalTagResolver $canonicalTagResolver,
    ) {}

    public function show(Request $request): View
    {
        $payload = $this->workingMemoryAssembler->forScope(
            (int) $request->user()->id,
            'global',
            'global'
        );

        return view('memory.show', $payload);
    }

    public function showProject(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        $payload = $this->workingMemoryAssembler->forScope(
            (int) $request->user()->id,
            'project',
            (string) $project->getKey()
        );

        return view('memory.show', array_merge($payload, [
            'scopeTitle' => $project->title,
            'project' => $project,
            'isProjectScope' => true,
        ]));
    }

    public function showProjectScope(Request $request, string $scopeKey): View
    {
        $normalizedKey = Str::of($scopeKey)->trim()->lower()->toString();
        if ($normalizedKey === '') {
            abort(404);
        }

        $payload = $this->workingMemoryAssembler->forScope(
            (int) $request->user()->id,
            'project',
            $normalizedKey
        );

        $title = str_contains($normalizedKey, '/')
            ? collect(explode('/', $normalizedKey, 2))
                ->map(fn (string $part): string => Str::of($part)->replace(['-', '_'], ' ')->squish()->title()->toString())
                ->implode(' / ')
            : Str::of($normalizedKey)->replace(['-', '_'], ' ')->squish()->title()->toString();

        return view('memory.show', array_merge($payload, [
            'scopeTitle' => $title,
            'isProjectScope' => true,
        ]));
    }

    public function showTag(Request $request): View|RedirectResponse
    {
        $request->validate([
            'tag' => 'required|string|max:100',
        ]);

        $tagSlugRaw = Str::of((string) $request->query('tag'))->trim()->toString();
        $normalizedSlug = TagSlug::from($tagSlugRaw);
        if ($normalizedSlug === '') {
            abort(404);
        }

        if ($normalizedSlug !== $tagSlugRaw) {
            return redirect()->route('memory.tag.show', ['tag' => $normalizedSlug]);
        }

        $canonical = $this->canonicalTagResolver->resolve((int) $request->user()->id, $normalizedSlug);
        $tagLabel = $canonical ?? $normalizedSlug;
        $scopeKey = Str::of($canonical ?? $normalizedSlug)->trim()->lower()->toString();

        $payload = $this->workingMemoryAssembler->forScope(
            (int) $request->user()->id,
            'tag',
            $scopeKey
        );

        return view('memory.show', array_merge($payload, [
            'scopeTitle' => $tagLabel,
            'isTagScope' => true,
            'tagSlugQuery' => $normalizedSlug,
            'tagRefreshScopeKey' => $normalizedSlug,
        ]));
    }
}
