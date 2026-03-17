@extends('layouts.idea')

@section('title', $query ? 'Search — IdeaTub' : 'IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">

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

    {{-- Hero --}}
    <p class="text-center text-[11px] font-semibold tracking-[0.12em] uppercase text-memory-violet mb-2.5">Your thinking space</p>
    <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-1.5">A calm archive for your ideas</h1>
    <p class="text-center text-sm text-slate-brand mb-9">Capture thoughts before they disappear.</p>

    {{-- Capture box (initial content in data attr to avoid @json breaking the x-data attribute) --}}
    @php
        $initialContent = (old('content') === '[object HTMLTextAreaElement]' ? '' : old('content', ''));
    @endphp
    <div
        x-data="captureBox()"
        data-initial-content="{{ e($initialContent) }}"
        data-focus-reply="{{ (isset($replyingTo) && $replyingTo) ? '1' : '0' }}"
        data-idea-index-url="{{ route('idea.index') }}"
        data-drafts-url="{{ route('ideas.drafts.index') }}"
        @focus-capture.window="focusCapture()"
        class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-4 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-3 transition-shadow focus-within:shadow-[0_4px_32px_rgba(109,106,247,0.16)] focus-within:border-memory-violet/50"
        :class="focusOverlayOpen ? 'fixed inset-0 z-50 flex items-center justify-center p-4' : ''"
    >
        {{-- Focus mode backdrop --}}
        <div
            x-show="focusOverlayOpen"
            x-cloak
            @click="focusOverlayOpen = false"
            class="absolute inset-0 bg-black/50 -z-10"
            aria-hidden="true"
        ></div>

        <div
            class="max-w-[600px] w-full"
            :class="focusOverlayOpen ? 'rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-4 shadow-[0_4px_24px_rgba(109,106,247,0.08)]' : ''"
            :role="focusOverlayOpen ? 'dialog' : null"
            :aria-modal="focusOverlayOpen ? 'true' : null"
            :aria-label="focusOverlayOpen ? 'Capture thought' : null"
        >
        {{-- AJAX success/error message --}}
        <div x-show="message" x-cloak class="mb-3 rounded-xl px-4 py-3 text-sm"
            :class="messageType === 'success' ? 'bg-neural-teal/10 border border-neural-teal/25 text-neural-teal' : 'bg-red-50 border border-red-200 text-red-600'"
            x-text="message">
        </div>

        {{-- Draft list (hidden in reply mode) --}}
        <div
            x-show="drafts.length > 0 && !isReplyMode"
            x-cloak
            class="mb-3"
        >
            <button
                type="button"
                @click="draftsExpanded = !draftsExpanded"
                class="text-sm text-slate-brand hover:text-deep-indigo font-medium"
            >
                Drafts (<span x-text="drafts.length"></span>)
            </button>
            <template x-if="draftsExpanded">
                <ul class="mt-2 space-y-2" role="list">
                    <template x-for="draft in drafts" :key="draft.id">
                        <li class="flex items-center justify-between gap-2 py-2 px-3 rounded-lg border border-memory-violet/15 bg-memory-violet/5 text-sm text-slate-brand">
                            <div class="min-w-0 flex-1">
                                <span class="line-clamp-1" x-text="draft.content_preview"></span>
                                <span class="text-[11px] text-slate-brand/60" x-text="draft.updated_at_human"></span>
                            </div>
                            <div class="shrink-0 flex items-center gap-1.5">
                                <button type="button" @click="loadDraft(draft.id)" class="text-xs font-medium text-memory-violet hover:text-deep-indigo">Resume</button>
                                <button type="button" @click="discardDraft(draft.id)" class="text-xs font-medium text-slate-brand/70 hover:text-red-600">Discard</button>
                            </div>
                        </li>
                    </template>
                </ul>
            </template>
        </div>

        <form
            method="POST"
            action="{{ route('thoughts.store') }}"
            @submit.prevent="submitCapture()"
            @keydown.meta.enter.prevent="submitCapture()"
        >
            @csrf
            <input type="hidden" name="parent_id" value="{{ isset($replyingTo) && $replyingTo ? $replyingTo->id : '' }}">

            @if (isset($replyingTo) && $replyingTo)
                <div class="flex items-start gap-2 mb-3 px-3 py-2 rounded-lg border border-memory-violet/15 bg-memory-violet/5">
                    <div class="flex-1 min-w-0">
                        <span class="text-[11px] font-semibold uppercase tracking-wider text-memory-violet/80">Replying to</span>
                        <p class="text-sm text-deep-indigo mt-0.5 line-clamp-2">{{ Str::limit($replyingTo->content, 80) }}</p>
                    </div>
                    <a href="{{ route('idea.index') }}" class="shrink-0 text-xs font-medium text-slate-brand hover:text-deep-indigo px-2.5 py-1.5 rounded-md border border-slate-200 hover:border-slate-300 bg-white/80 transition-colors" title="Cancel reply">Cancel</a>
                </div>
            @endif

            <textarea
                name="content"
                id="content"
                rows="3"
                x-ref="captureTextarea"
                x-model="content"
                @keydown.meta.enter.prevent="submitCapture()"
                @keydown.ctrl.enter.prevent="submitCapture()"
                :aria-invalid="!!errorField || {{ $errors->has('content') ? 'true' : 'false' }}"
                aria-describedby="content-error"
                placeholder="What are you thinking?"
                class="w-full bg-transparent border-none outline-none resize-none text-sm text-deep-indigo placeholder-slate-brand/40 leading-relaxed"
            ></textarea>

            <p id="content-error" class="mt-1 text-xs text-red-500" x-show="errorField || {{ $errors->has('content') ? 'true' : 'false' }}" x-text="errorField">@if($errors->has('content')){{ $errors->first('content') }}@endif</p>

            <div class="mt-2 flex items-center gap-2">
                <input type="checkbox" name="no_chunking" id="no_chunking" value="1" class="rounded border-slate-300 text-memory-violet focus:ring-memory-violet/30"
                    x-model="noChunking"
                    {{ old('no_chunking') ? 'checked' : '' }}>
                <label for="no_chunking" class="text-[11px] text-slate-brand/70">Don't split into sections (long docs are normally split at headings)</label>
            </div>

            <div class="flex items-center justify-between mt-2.5 pt-2.5 border-t border-memory-violet/8">
                <span class="text-[11px] text-slate-brand/40">⌘ + Enter to store · ⌘/ to focus</span>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        x-ref="focusButton"
                        @click="toggleFocus()"
                        class="text-xs font-medium text-slate-brand hover:text-deep-indigo"
                    >
                        Focus
                    </button>
                    <button
                        type="button"
                        x-show="focusOverlayOpen"
                        x-cloak
                        @click="focusOverlayOpen = false"
                        class="text-xs font-medium text-slate-brand hover:text-deep-indigo"
                    >
                        Close
                    </button>
                    <button
                        type="submit"
                        :disabled="saving"
                        class="text-xs font-medium text-white px-4 py-1.5 rounded-lg transition-opacity hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed"
                        style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                    >
                        <span x-show="!saving">Store thought</span>
                        <span x-show="saving" x-cloak>Saving…</span>
                    </button>
                </div>
            </div>
        </form>
        </div>
    </div>

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
            @include('idea.index_thought_cards', ['thoughts' => $thoughts, 'replyableIndexStart' => 0])
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
