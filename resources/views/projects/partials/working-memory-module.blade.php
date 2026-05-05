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
    <section class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 mb-8">
        <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Working memory</h2>
        <p class="text-sm text-slate-brand mb-3">{{ $wmProjectStatusLine }}</p>
        <a href="{{ route('projects.memory.show', $project) }}" class="text-sm font-medium text-memory-violet hover:underline">Open project working memory</a>
    </section>
@endauth
