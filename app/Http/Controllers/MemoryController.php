<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\WorkingMemoryVersion;
use App\Services\Tags\UserCanonicalTagResolver;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use App\Services\WorkingMemory\WorkingMemoryVersionCatalog;
use App\Support\TagSlug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MemoryController extends Controller
{
    public function __construct(
        private readonly WorkingMemoryAssembler $workingMemoryAssembler,
        private readonly UserCanonicalTagResolver $canonicalTagResolver,
        private readonly WorkingMemoryVersionCatalog $versionCatalog,
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

    public function historyGlobal(Request $request): View
    {
        $versions = $this->versionCatalog->listForScope(
            (int) $request->user()->id,
            'global',
            'global',
        );

        return view('memory.history', $this->historyViewData(
            scopeTitle: 'Global',
            currentMemoryUrl: route('memory.show'),
            versions: $versions,
        ));
    }

    public function historyProject(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        $versions = $this->versionCatalog->listForScope(
            (int) $request->user()->id,
            'project',
            (string) $project->getKey(),
        );

        return view('memory.history', $this->historyViewData(
            scopeTitle: $project->title,
            currentMemoryUrl: route('projects.memory.show', $project),
            versions: $versions,
        ));
    }

    public function showVersion(Request $request, WorkingMemoryVersion $version): View
    {
        $versionModel = $this->versionCatalog->showForUser(
            (int) $request->user()->id,
            (string) $version->id,
        );
        $versionModel->loadMissing('workingMemory');
        $memory = $versionModel->workingMemory;
        $payload = $this->versionCatalog->toDetailPayload($versionModel);

        $scopeType = (string) $memory->scope_type;
        $scopeKey = (string) $memory->scope_key;
        $isProjectScope = $scopeType === 'project';
        $project = null;

        if ($isProjectScope && Str::isUuid($scopeKey)) {
            $project = Project::query()
                ->where('user_id', (int) $request->user()->id)
                ->whereKey($scopeKey)
                ->first();
        }

        $currentMemoryUrl = match ($scopeType) {
            'project' => $project !== null
                ? route('projects.memory.show', $project)
                : route('memory.project-scope.show', ['scopeKey' => $scopeKey]),
            'tag' => route('memory.tag.show', ['tag' => $scopeKey]),
            default => route('memory.show'),
        };

        $historyUrl = $isProjectScope && $project !== null
            ? route('projects.memory.versions', $project)
            : ($scopeType === 'global' ? route('memory.versions') : null);

        $scopeTitle = match ($scopeType) {
            'global' => 'Global',
            'project' => $project?->title ?? Str::of($scopeKey)->replace(['-', '_'], ' ')->squish()->title()->toString(),
            'tag' => Str::of($scopeKey)->replace(['-', '_'], ' ')->squish()->title()->toString(),
            default => Str::of($scopeKey)->replace(['-', '_'], ' ')->squish()->title()->toString(),
        };

        return view('memory.version', array_merge($payload, [
            'version' => $versionModel,
            'scopeTitle' => $scopeTitle,
            'scopeType' => $scopeType,
            'currentMemoryUrl' => $currentMemoryUrl,
            'historyUrl' => $historyUrl,
            'readOnly' => true,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function historyViewData(string $scopeTitle, string $currentMemoryUrl, LengthAwarePaginator $versions): array
    {
        return [
            'scopeTitle' => $scopeTitle,
            'currentMemoryUrl' => $currentMemoryUrl,
            'versions' => $versions,
            'versionRows' => $versions->map(
                fn (WorkingMemoryVersion $version): array => array_merge(
                    $this->versionCatalog->toListItem($version),
                    ['version' => $version],
                )
            ),
        ];
    }
}
