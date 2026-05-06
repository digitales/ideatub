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
        <form method="POST" action="{{ route('working-memory.refresh.project', $project) }}" class="mb-3" onsubmit="const button=this.querySelector('button[type=submit]'); if(!button||button.disabled){return false;} button.disabled=true; button.setAttribute('aria-busy','true'); return true;">
            @csrf
            <button type="submit" class="inline-flex items-center rounded-lg bg-memory-violet px-3 py-2 text-sm font-medium text-white hover:bg-memory-violet/90">
                Refresh working memory
            </button>
        </form>
        <p class="text-xs text-slate-brand/80 mb-3">Queues a consolidated rebuild.</p>
        <a href="{{ route('projects.memory.show', $project) }}" class="text-sm font-medium text-memory-violet hover:underline">Open project working memory</a>
    </section>
@endauth
