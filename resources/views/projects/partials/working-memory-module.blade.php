@auth
    @php
        $wmProjectScopeKey = \Illuminate\Support\Str::lower((string) $project->id);
        $wmProject = \App\Models\WorkingMemory::query()
            ->where('user_id', auth()->id())
            ->where('scope_type', 'project')
            ->where('scope_key', $wmProjectScopeKey)
            ->first();
        $wmProjectStatusLine = $wmProject
            ? ucfirst((string) ($wmProject->freshness_state ?? 'unknown')).($wmProject->last_refreshed_at ? ' · refreshed '.$wmProject->last_refreshed_at->diffForHumans() : '')
            : 'Not built yet for this project.';
    @endphp
    <section class="ideatub-surface px-5 py-5">
        <h2 class="text-sm font-semibold text-deep-indigo">Working memory</h2>
        <p class="mt-1 text-xs text-slate-brand/70">{{ $wmProjectStatusLine }}</p>
        @include('components.working-memory-refresh-form', [
            'action' => route('working-memory.refresh.project', $project),
            'formClass' => 'mt-4 mb-3',
            'buttonClass' => 'ideatub-btn-primary w-full px-3 py-2 text-sm',
        ])
        <a href="{{ route('projects.memory.show', $project) }}" class="text-sm font-medium text-memory-violet hover:underline">Open project working memory</a>
    </section>
@endauth
