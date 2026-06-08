@php
    $navBtnClass = 'inline-flex items-center rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet transition-colors hover:bg-memory-violet/5 hover:text-memory-violet/80';
    $actionBtnClass = $navBtnClass;
    $forceBtnClass = 'inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-brand transition-colors hover:bg-white hover:text-deep-indigo';
    $scopeLabel = $isTag
        ? ($scopeTitle ?? 'Tag')
        : ($isProject
            ? ($scopeTitle ?? ($project->title ?? 'Project'))
            : 'Global');
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0 flex-1">
            @if ($isProject || $isTag)
                <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-memory-violet/80">{{ $scopeLabel }}</p>
                <h1 class="mt-1 text-[28px] font-semibold leading-snug text-deep-indigo">Working memory</h1>
                <p class="mt-1 max-w-prose text-sm text-slate-brand">
                    @if ($isTag)
                        Synthesized from captures with this tag.
                    @else
                        Synthesized from captures linked to this project.
                    @endif
                </p>
            @else
                <h1 class="text-[28px] font-semibold leading-snug text-deep-indigo">Working memory</h1>
                <p class="mt-1 max-w-prose text-sm text-slate-brand">Synthesized from your captures.</p>
            @endif
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] {{ $freshnessClasses }}">
                {{ $freshness }}
            </span>
            @if (($baseline_build_type ?? '') === 'external')
                <span class="inline-flex items-center rounded-full border border-memory-violet/30 bg-memory-violet/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-memory-violet">
                    Synced from agent
                </span>
            @endif
        </div>
    </div>

    @if ($externalProtected)
        <div class="rounded-xl border border-memory-violet/20 bg-memory-violet/5 px-4 py-3">
            <p class="text-sm text-slate-brand">
                This memory is synced from your agent. Re-run your agent sync to update it.
            </p>
        </div>
    @endif

    <div class="flex flex-col gap-3 border-t border-memory-violet/10 pt-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <nav class="flex flex-wrap items-center gap-2" aria-label="Working memory navigation">
            @if ($isProject && ! empty($project))
                <a href="{{ route('projects.show', $project) }}" class="{{ $navBtnClass }}">
                    Project page
                </a>
            @endif
            @if ($isTag && ! empty($tagSlugQuery ?? null))
                <a href="{{ route('idea.stream', ['tag' => $tagSlugQuery]) }}" class="{{ $navBtnClass }}">
                    Tag page
                </a>
            @endif
            @if (! $isTag && (! $isProject || ! empty($project)))
                <a
                    href="{{ $isProject && ! empty($project) ? route('projects.memory.versions', $project) : route('memory.versions') }}"
                    class="{{ $navBtnClass }}"
                >
                    History
                </a>
            @endif
            @if (! $isProject && ! $isTag && config('features.working_memory_ui'))
                <a href="{{ route('memory.scopes.index') }}" class="{{ $navBtnClass }}">
                    All memories
                </a>
            @endif
            @if (! $isProject && ! $isTag && config('features.working_memory_insights'))
                <a href="{{ route('memory.insights') }}" class="{{ $navBtnClass }}">
                    Insights
                </a>
            @endif
        </nav>

        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
            @include('components.working-memory-refresh-form', [
                'action' => $refreshAction,
                'buttonClass' => $actionBtnClass,
                'hiddenFields' => $isTag ? ['tag' => $tagRefreshScopeKey] : [],
                'showForceButton' => $externalProtected && $aiAuthoringEnabled,
                'forceButtonClass' => $forceBtnClass,
            ])
        </div>
    </div>
</div>
