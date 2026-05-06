@auth
    @php
        $wmHome = \App\Models\WorkingMemory::query()
            ->where('user_id', auth()->id())
            ->where('scope_type', 'global')
            ->where('scope_key', 'global')
            ->first();
    @endphp
    <div class="mb-6 rounded-xl border border-memory-violet/15 bg-white/70 backdrop-blur px-4 py-3 shadow-[0_2px_16px_rgba(109,106,247,0.06)]">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1">Working memory</p>
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
            <a href="{{ route('memory.show') }}" class="shrink-0 text-xs font-medium text-white px-3 py-1.5 rounded-lg transition-opacity hover:opacity-90" style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
                Open
            </a>
        </div>
    </div>
@endauth
