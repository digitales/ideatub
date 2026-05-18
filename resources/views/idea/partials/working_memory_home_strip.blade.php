@auth
    @php
        $wmHome = \App\Models\WorkingMemory::query()
            ->where('user_id', auth()->id())
            ->where('scope_type', 'global')
            ->where('scope_key', 'global')
            ->first();
    @endphp
    <div class="ideatub-surface mb-8 px-4 py-3.5">
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
            <a href="{{ route('memory.show') }}" class="ideatub-btn-primary shrink-0 px-3.5 py-2 text-xs">
                Open
            </a>
        </div>
    </div>
@endauth
