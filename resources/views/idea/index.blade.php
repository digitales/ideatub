@extends('layouts.idea')

@section('title', $query ? 'Search — IdeaTub' : 'IdeaTub')

@section('content')
@php
    $isDashboard = ! $query && ! empty($morningBrief);
    $containerClass = $isDashboard ? 'max-w-6xl' : 'max-w-2xl';
@endphp
<div class="{{ $containerClass }} mx-auto px-4 sm:px-6 lg:px-8 pt-8 pb-20">

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
    @elseif ($isDashboard)
        <header class="mb-6 lg:mb-8 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90 mb-1">Morning brief</p>
                <h1 class="text-2xl sm:text-3xl font-semibold tracking-tight text-deep-indigo leading-snug">{{ $morningBrief->greeting }}</h1>
                <p class="mt-1 text-sm text-slate-brand/80 max-w-[42ch]">Pick up where you left off, or capture something new.</p>
            </div>
            @includeWhen(config('features.working_memory_ui'), 'idea.partials.working_memory_home_strip', ['variant' => 'compact'])
        </header>
    @else
        @include('idea.partials.page_shell_header', [
            'eyebrow' => 'Your thinking space',
            'title' => 'A calm archive for your ideas',
            'subtitle' => 'Capture thoughts before they disappear.',
            'centered' => true,
        ])
    @endif

    @if (! $isDashboard)
        @includeWhen(config('features.working_memory_ui'), 'idea.partials.working_memory_home_strip')
    @endif

    {{-- Capture box (initial content in data attr to avoid @json breaking the x-data attribute) --}}
    @php
        $initialContent = old('youtube_url', old('content', ''));
        $initialContent = ($initialContent === '[object HTMLTextAreaElement]' ? '' : $initialContent);
        $forceHomeVideoMode = filled(old('youtube_url'));
        $importUploadsEnabled = (bool) config('features.file_upload', false)
            && \Illuminate\Support\Facades\Route::has('imports.quick')
            && ! app(\App\Services\DemoMode::class)->enabled();
    @endphp

    @if ($isDashboard && $morningBrief->hasCards())
        <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_280px] lg:gap-8 lg:items-start">
            {{-- Sidebar: quick actions (desktop right, mobile after capture) --}}
            <aside class="order-2 lg:order-none lg:col-start-2 lg:row-start-1 lg:row-span-2 mb-6 lg:mb-0 lg:sticky lg:top-24 self-start">
                @include('idea.partials.morning_brief', ['morningBrief' => $morningBrief, 'variant' => 'sidebar'])
            </aside>

            {{-- Main: capture --}}
            <div class="order-1 lg:order-none lg:col-start-1 lg:row-start-1 min-w-0">
                @include('idea.partials.capture_box', [
                    'placement' => 'inline',
                    'initialContent' => $initialContent,
                    'forceHomeVideoMode' => $forceHomeVideoMode,
                    'importUploadsEnabled' => $importUploadsEnabled,
                    'replyingTo' => $replyingTo ?? null,
                    'replyingToPreview' => $replyingToPreview ?? null,
                    'fullWidth' => true,
                ])
            </div>

            {{-- Main: thoughts feed --}}
            <div class="order-3 lg:order-none lg:col-start-1 lg:row-start-2 min-w-0 mt-6 lg:mt-8">
                @include('idea.partials.index_thoughts_section', [
                    'query' => $query,
                    'thoughts' => $thoughts,
                    'cards' => $cards,
                ])
            </div>
        </div>
    @elseif ($isDashboard)
        <div class="max-w-3xl">
            @include('idea.partials.capture_box', [
                'placement' => 'inline',
                'initialContent' => $initialContent,
                'forceHomeVideoMode' => $forceHomeVideoMode,
                'importUploadsEnabled' => $importUploadsEnabled,
                'replyingTo' => $replyingTo ?? null,
                'replyingToPreview' => $replyingToPreview ?? null,
                'fullWidth' => true,
            ])
        </div>

        <div class="mt-8">
            @include('idea.partials.index_thoughts_section', [
                'query' => $query,
                'thoughts' => $thoughts,
                'cards' => $cards,
            ])
        </div>
    @else
        @include('idea.partials.capture_box', [
            'placement' => 'inline',
            'initialContent' => $initialContent,
            'forceHomeVideoMode' => $forceHomeVideoMode,
            'importUploadsEnabled' => $importUploadsEnabled,
            'replyingTo' => $replyingTo ?? null,
            'replyingToPreview' => $replyingToPreview ?? null,
        ])

        @include('idea.partials.index_thoughts_section', [
            'query' => $query,
            'thoughts' => $thoughts,
            'cards' => $cards,
        ])
    @endif

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
