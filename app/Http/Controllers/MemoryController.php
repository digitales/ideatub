<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Tags\UserCanonicalTagResolver;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
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

    public function showTag(Request $request): View
    {
        $request->validate([
            'tag' => 'required|string|max:100',
        ]);

        $tagSlug = Str::of((string) $request->query('tag'))->trim()->toString();
        $canonical = $this->canonicalTagResolver->resolve((int) $request->user()->id, $tagSlug);
        $tagLabel = $canonical ?? $tagSlug;
        $scopeKey = Str::of($canonical ?? $tagSlug)->trim()->lower()->toString();

        $payload = $this->workingMemoryAssembler->forScope(
            (int) $request->user()->id,
            'tag',
            $scopeKey
        );

        return view('memory.show', array_merge($payload, [
            'scopeTitle' => $tagLabel,
            'isTagScope' => true,
            'tagSlugQuery' => $tagSlug,
            'tagRefreshScopeKey' => $scopeKey,
        ]));
    }
}
