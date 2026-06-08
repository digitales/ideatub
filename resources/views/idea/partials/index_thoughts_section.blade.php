<div
    x-data="{
        selectedThoughtIndex: 0,
        handleThoughtNav(detail) {
            if (!detail || !detail.direction) return;
            const cards = this.$el.querySelectorAll('[data-reply-href]');
            const max = Math.max(0, cards.length - 1);
            if (detail.direction === 'next') this.selectedThoughtIndex = Math.min(this.selectedThoughtIndex + 1, max);
            else this.selectedThoughtIndex = Math.max(this.selectedThoughtIndex - 1, 0);
            const card = cards[this.selectedThoughtIndex];
            if (card) card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        },
        handleThoughtReply() {
            const cards = this.$el.querySelectorAll('[data-reply-href]');
            const card = cards[this.selectedThoughtIndex];
            const href = card?.dataset?.replyHref;
            if (href) window.location.href = href;
        }
    }"
    @thought-nav.window="handleThoughtNav($event.detail)"
    @thought-reply.window="handleThoughtReply()"
>
    <div id="search-results" class="flex items-end justify-between gap-4 mb-4 pb-3 border-b border-deep-indigo/[0.06] scroll-mt-[5rem]" role="region" aria-label="{{ $query ? 'Search results' : 'Recent thoughts' }}">
        <div class="min-w-0">
            <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-slate-brand/55">
                @if ($query)
                    Results for "{{ e($query) }}"
                @else
                    Recent thoughts
                @endif
            </span>
            @if (!$thoughts->isEmpty())
                <p class="text-[11px] text-slate-brand/40 mt-0.5">j / k to move · Enter to reply</p>
            @endif
        </div>
        <span class="text-[11px] font-medium text-slate-brand/45 shrink-0">{{ $thoughts instanceof \Illuminate\Pagination\LengthAwarePaginator ? $thoughts->total() : count($thoughts) }} stored</span>
    </div>

    @if (!$thoughts->isEmpty())
        <div id="index-thoughts-list"
            @if(!$query) data-index-refetch-url="{{ route('idea.index') }}"
                data-index-since="{{ $thoughts->first()->created_at->toIso8601String() }}"
            @endif>
            @include('idea.index_thought_cards', ['cards' => $cards])
        </div>
    @else
        <div class="rounded-xl border border-memory-violet/10 bg-white/50 px-4 py-8 text-center text-sm text-slate-brand/50">
            @if ($query)
                No thoughts match your search. Try different words or capture a new one above.
            @else
                No thoughts yet. What are you thinking?
            @endif
        </div>
    @endif

    {{-- Search: infinite scroll sentinel; non-search or single-page: pagination links --}}
    @if ($query && $thoughts instanceof \Illuminate\Pagination\LengthAwarePaginator && $thoughts->hasMorePages())
        <div id="index-load-more-sentinel" class="h-4 mt-4" data-index-search-url="{{ route('idea.index', ['q' => $query]) }}" data-index-total="{{ $thoughts->total() }}"></div>
    @elseif($thoughts instanceof \Illuminate\Pagination\LengthAwarePaginator && $thoughts->hasMorePages())
        <div class="text-center pt-4">
            {{ $thoughts->links('pagination.idea') }}
        </div>
    @endif
</div>
