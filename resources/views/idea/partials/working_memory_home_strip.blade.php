@auth
    @php
        $wmHome = \App\Models\WorkingMemory::query()
            ->where('user_id', auth()->id())
            ->where('scope_type', 'global')
            ->where('scope_key', 'global')
            ->first();
    @endphp
    <div class="mb-8 rounded-2xl bg-white px-4 py-3.5 ring-1 ring-deep-indigo/[0.06] shadow-[0_1px_3px_rgba(30,37,71,0.04)]">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/80 mb-1">Working memory</p>
                @if ($wmHome)
                    <p class="text-sm text-slate-brand">
                        <span class="font-medium text-deep-indigo">{{ $wmHome->freshness_state ?? 'unknown' }}</span>
                        @if ($wmHome->last_refreshed_at)
                            <span class="text-slate-brand/70"> · refreshed {{ $wmHome->last_refreshed_at->diffForHumans() }}</span>
                        @endif
                    </p>
                @else
                    <p class="text-sm text-slate-brand">Not built yet. Open working memory to generate your global summary.</p>
                @endif
            </div>
            <a href="{{ route('memory.show') }}" class="shrink-0 rounded-xl bg-memory-violet px-3.5 py-2 text-xs font-medium text-white shadow-sm transition hover:bg-memory-violet/90">
                Open
            </a>
        </div>
    </div>
@endauth
