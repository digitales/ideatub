@extends('layouts.idea')

@section('title', $query ? 'Search — IdeaTub' : 'IdeaTub')

@section('content')
<div class="max-w-2xl mx-auto px-6 pt-10 pb-20">

    {{-- Flash messages --}}
    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-600">
            {{ session('error') }}
        </div>
    @endif

    @if ($query)
        <p class="text-center text-[11px] font-semibold tracking-[0.12em] uppercase text-memory-violet mb-2.5">Search</p>
        <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-9">Find a memory</h1>
    @elseif (! empty($morningBrief))
        @include('idea.partials.morning_brief', ['morningBrief' => $morningBrief])
    @else
        @include('idea.partials.page_shell_header', [
            'eyebrow' => 'Your thinking space',
            'title' => 'A calm archive for your ideas',
            'subtitle' => 'Capture thoughts before they disappear.',
            'centered' => true,
        ])
    @endif

    @includeWhen(config('features.working_memory_ui'), 'idea.partials.working_memory_home_strip')

    {{-- Capture box (initial content in data attr to avoid @json breaking the x-data attribute) --}}
    @php
        $initialContent = old('youtube_url', old('content', ''));
        $initialContent = ($initialContent === '[object HTMLTextAreaElement]' ? '' : $initialContent);
        $forceHomeVideoMode = filled(old('youtube_url'));
        $importUploadsEnabled = (bool) config('features.file_upload', false)
            && \Illuminate\Support\Facades\Route::has('imports.quick')
            && ! app(\App\Services\DemoMode::class)->enabled();
    @endphp
    @include('idea.partials.capture_box', [
        'placement' => 'inline',
        'initialContent' => $initialContent,
        'forceHomeVideoMode' => $forceHomeVideoMode,
        'importUploadsEnabled' => $importUploadsEnabled,
        'replyingTo' => $replyingTo ?? null,
        'replyingToPreview' => $replyingToPreview ?? null,
    ])

    {{-- Thoughts list --}}
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
    <div id="search-results" class="flex items-center justify-between mt-9 mb-3.5 scroll-mt-[5rem]" role="region" aria-label="{{ $query ? 'Search results' : 'Recent thoughts' }}">
        <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-slate-brand/50">
            @if ($query)
                Results for "{{ e($query) }}"
            @else
                Recent thoughts
            @endif
        </span>
        <span class="text-[11px] text-slate-brand/30">{{ $thoughts instanceof \Illuminate\Pagination\LengthAwarePaginator ? $thoughts->total() : count($thoughts) }} stored</span>
    </div>
    @if (!$thoughts->isEmpty())
        <p class="text-[11px] text-slate-brand/40 mb-2">j / k to move · Enter to reply</p>
    @endif

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

</div>

@if(!$query && !$thoughts->isEmpty())
@push('scripts')
<script>
(function() {
    var list = document.getElementById('index-thoughts-list');
    if (!list || !window.ideatub || !window.ideatub.realtime) return;
    var cfg = window.ideatub.realtime;
    var refetchUrl = list.getAttribute('data-index-refetch-url');
    if (!refetchUrl) return;
    function refetchIndex() {
        fetch(refetchUrl, { method: 'GET', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.html) list.innerHTML = data.html;
                if (data.latest_created_at) list.setAttribute('data-index-since', data.latest_created_at);
            })
            .catch(function() {});
    }
    if (cfg.driver === 'polling' || !cfg.reverb_key) {
        setInterval(function() {
            var since = list.getAttribute('data-index-since');
            if (!since || !cfg.realtime_check_url) return;
            fetch(cfg.realtime_check_url + '?since=' + encodeURIComponent(since), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(data) { if (data.has_new) refetchIndex(); })
                .catch(function() {});
        }, 20000);
    } else if (cfg.driver === 'reverb' && cfg.reverb_key && cfg.user_id) {
        function trySubscribe() {
            if (!window.Echo) { setTimeout(trySubscribe, 100); return; }
            try {
                window.Echo.private('App.Models.User.' + cfg.user_id).listen('.ThoughtCreated', refetchIndex);
            } catch (e) { console.warn('Echo subscribe failed:', e); }
        }
        trySubscribe();
    }
})();
</script>
@endpush
@endif

@if($query)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('search-results');
    if (el) el.scrollIntoView({ behavior: 'auto', block: 'start' });
});
</script>
@endpush
@endif

@if($query && $thoughts instanceof \Illuminate\Pagination\LengthAwarePaginator && $thoughts->hasMorePages())
@push('scripts')
<script>
(function () {
    var sentinel = document.getElementById('index-load-more-sentinel');
    var list = document.getElementById('index-thoughts-list');
    if (!sentinel || !list) return;

    var baseUrl = sentinel.getAttribute('data-index-search-url');
    var nextPage = 2;
    var loading = false;

    function replyableCount() {
        return Array.from(list.querySelectorAll('[data-reply-href]')).filter(function (el) { return el.getAttribute('data-reply-href'); }).length;
    }

    function loadMore() {
        if (loading) return;
        loading = true;
        var url = baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + 'page=' + nextPage + '&replyable_offset=' + replyableCount();
        fetch(url, {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.html) {
                list.insertAdjacentHTML('beforeend', data.html);
            }
            if (data.has_more && data.next_page) {
                nextPage = data.next_page;
            } else {
                if (sentinel.parentNode) sentinel.parentNode.removeChild(sentinel);
                if (typeof observer !== 'undefined') observer.disconnect();
            }
        })
        .catch(function () {})
        .finally(function () { loading = false; });
    }

    var observer = new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting) loadMore();
    }, { rootMargin: '200px', threshold: 0 });
    observer.observe(sentinel);
})();
</script>
@endpush
@endif
@endsection
