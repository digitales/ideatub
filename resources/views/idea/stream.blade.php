@extends('layouts.idea')

@php
    use App\Support\TagSlug;
    use App\Support\ThoughtTypeNavigation;
    $__collectionKey = $streamCollectionKey ?? ((isset($streamJira) && $streamJira) ? 'jira' : null);
    $__typedStreamRouteName = $__collectionKey ? ThoughtTypeNavigation::routeName($__collectionKey) : null;
@endphp

@section('title', $__collectionKey ? ThoughtTypeNavigation::documentTitle($__collectionKey) : ($tag ? 'Tag: ' . e($tag) . ' — IdeaTub' : 'Stream — IdeaTub'))

@section('content')
        @php
            $__streamTitle = $__collectionKey
                ? ThoughtTypeNavigation::collectionLabel($__collectionKey)
                : ($tag ? 'Tag: '.e($tag) : 'Your stream');
            $__streamSubtitle = $tag
                ? 'Thoughts tagged for this topic.'
                : ($__collectionKey
                    ? 'Filtered view of your captured thoughts.'
                    : 'Everything you have captured, newest first.');
        @endphp
        <div
            x-data="streamLayout('{{ $streamLayout }}')"
            x-init="applyLayout()"
            data-stream-layout="{{ $streamLayout }}"
            class="mx-auto w-full max-w-7xl px-6 pt-10 pb-20"
        >
            @include('idea.partials.page_shell_header', [
                'eyebrow' => $tag ? 'Tagged stream' : 'Stream',
                'title' => $__streamTitle,
                'subtitle' => $__streamSubtitle,
                'actions' => $thoughts->isEmpty() ? null : view('idea.partials.stream_layout_toggle'),
            ])

            @if (! $tag)
                <div class="stream-chrome">
                    @include('idea.partials.stream_type_nav', ['active' => $__collectionKey ?? 'all'])
                </div>
            @endif

            @if($tag)
                @php
                    $refreshTagSlug = ($tagSlug !== null && $tagSlug !== '') ? $tagSlug : TagSlug::from((string) $tag);
                    $refreshTagAction = \Illuminate\Support\Facades\URL::signedRoute('working-memory.refresh.tag', ['tag' => $refreshTagSlug]);
                @endphp
                <div class="mb-4 flex items-center justify-center gap-3 flex-wrap">
                    <a href="{{ route('idea.stream') }}" class="text-[12px] font-medium text-memory-violet hover:underline">
                        All thoughts
                    </a>
                    <a href="{{ route('memory.tag.show', ['tag' => $refreshTagSlug]) }}" class="text-[12px] font-medium text-memory-violet hover:underline">
                        Open tag working memory
                    </a>
                    @include('components.working-memory-refresh-form', [
                        'action' => $refreshTagAction,
                        'buttonClass' => 'rounded-full border border-memory-violet/40 px-3 py-1 text-[12px] font-medium text-memory-violet transition hover:bg-memory-violet/5',
                        'hiddenFields' => ['tag' => $refreshTagSlug],
                    ])
                </div>
            @endif

            @if($thoughts->isEmpty())
                <div class="ideatub-surface-muted px-6 py-10 text-center text-sm text-slate-brand/60">
                    @if($__collectionKey === 'jira')
                        No Jira activity yet. @if(config('services.jira.enabled', true))<a href="{{ route('settings.jira.index') }}" class="text-memory-violet hover:underline">Sync from Jira settings</a>.@endif
                    @elseif($__collectionKey === 'email')
                        No email thoughts yet.
                    @elseif($__collectionKey === 'research')
                        No research yet.
                    @elseif($__collectionKey === 'plan')
                        No plans yet.
                    @elseif($__collectionKey === 'meeting')
                        No meetings yet.
                    @elseif($__collectionKey === 'video')
                        No videos yet. <a href="{{ route('idea.index') }}" class="text-memory-violet hover:underline">Capture a video from the home page</a>.
                    @elseif($tag)
                        No thoughts with tag ‘{{e($tag)}}’. <a href="{{route('idea.stream')}}" class="text-memory-violet hover:underline">All thoughts</a>
                    @else
                        No thoughts yet. <a href="{{route('idea.index')}}" class="text-memory-violet hover:underline">Capture
                            one from the home page</a>.
                    @endif
                </div>
            @else
                <div :class="layout === 'list' ? 'mx-auto max-w-2xl' : ''">
                    <p class="mb-4 text-base text-slate-brand/55 tabular-nums sm:text-sm" id="stream-count-line">
                        Showing <span id="stream-showing-count" class="font-medium text-deep-indigo/80">{{ $thoughts->count() }}</span> of <span id="stream-total-count" class="font-medium text-deep-indigo/80">{{ $thoughts->total() }}</span> thoughts
                    </p>
                    <div
                        id="stream-thoughts-list"
                        data-stream-refetch-url="{{ $__typedStreamRouteName ? route($__typedStreamRouteName) : ($tagSlug ? route('idea.stream', ['tag' => $tagSlug]) : route('idea.stream')) }}?page=1"
                        data-stream-since="{{ $streamSince }}"
                    >
                        @include('idea.stream_thoughts', ['cards' => $cards])
                    </div>
                    @if($thoughts->hasMorePages())
                        <div id="stream-load-more-sentinel" class="mt-4 h-4" data-stream-base-url="{{ $__typedStreamRouteName ? route($__typedStreamRouteName) : ($tagSlug ? route('idea.stream', ['tag' => $tagSlug]) : route('idea.stream')) }}" data-stream-total="{{ $thoughts->total() }}"></div>
                    @endif
                </div>
            @endif
        </div>

        @push('scripts')
            @if(!$thoughts->isEmpty())
                <script>
                (function() {
                    var list = document.getElementById('stream-thoughts-list');
                    if (!list || !window.ideatub || !window.ideatub.realtime) return;
                    var cfg = window.ideatub.realtime;
                    var refetchUrl = list.getAttribute('data-stream-refetch-url');
                    if (!refetchUrl) return;
                    function refetchStream() {
                        fetch(refetchUrl, { method: 'GET', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.html) { list.innerHTML = data.html; if (typeof streamLayoutCheckOverflow === 'function') streamLayoutCheckOverflow(); }
                                var showing = document.getElementById('stream-showing-count');
                                var total = document.getElementById('stream-total-count');
                                if (showing && data.count !== undefined) showing.textContent = data.count;
                                if (total && data.total !== undefined) total.textContent = data.total;
                                if (data.latest_created_at) list.setAttribute('data-stream-since', data.latest_created_at);
                            })
                            .catch(function() {});
                    }
                    if (cfg.driver === 'polling' || !cfg.reverb_key) {
                        var sinceEl = list;
                        setInterval(function() {
                            var since = sinceEl.getAttribute('data-stream-since');
                            if (!since || !cfg.realtime_check_url) return;
                            fetch(cfg.realtime_check_url + '?since=' + encodeURIComponent(since), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                                .then(function(r) { return r.json(); })
                                .then(function(data) { if (data.has_new) refetchStream(); })
                                .catch(function() {});
                        }, 20000);
                    } else if (cfg.driver === 'reverb' && cfg.reverb_key && cfg.user_id) {
                        function trySubscribe() {
                            if (!window.Echo) { setTimeout(trySubscribe, 100); return; }
                            try {
                                window.Echo.private('App.Models.User.' + cfg.user_id).listen('.ThoughtCreated', refetchStream);
                            } catch (e) { console.warn('Echo subscribe failed:', e); }
                        }
                        trySubscribe();
                    }
                })();
                </script>
            @if($thoughts->hasMorePages())
                <script>
                (function() {
                    var sentinel = document.getElementById('stream-load-more-sentinel');
                    var list = document.getElementById('stream-thoughts-list');
                    var showingEl = document.getElementById('stream-showing-count');
                    if (!sentinel || !list || !showingEl) return;

                    var baseUrl = sentinel.getAttribute('data-stream-base-url');
                    var nextPage = 2;
                    var loading = false;

                    function loadMore() {
                        if (loading) return;
                        loading = true;
                        var url = baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + 'page=' + nextPage;
                        fetch(url, {
                                method: 'GET',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            })
                            .then(function(r) {
                                return r.json();
                            })
                            .then(function(data) {
                                if (data.html) {
                                    list.insertAdjacentHTML('beforeend', data.html);
                                    var current = parseInt(showingEl.textContent, 10) + (data.count || 0);
                                    showingEl.textContent = current;
                                    if (typeof streamLayoutCheckOverflow === 'function') streamLayoutCheckOverflow();
                                }
                                if (data.has_more && data.next_page) {
                                    nextPage = data.next_page;
                                } else {
                                    if (sentinel.parentNode) sentinel.parentNode.removeChild(sentinel);
                                    observer.disconnect();
                                }
                            })
                            .catch(function() {
                                loading = false;
                            })
                            .finally(function() {
                                loading = false;
                            });
                    }

                    var observer = new IntersectionObserver(function(entries) {
                        if (entries[0].isIntersecting) loadMore();
                    }, {
                        rootMargin: '200px',
                        threshold: 0
                    });
                    observer.observe(sentinel);
                })();
                </script>
            @endif
                <script>
                function streamLayout(initial) {
                    return {
                        layout: initial || 'list',
                        setLayout(mode) {
                            if (this.layout === mode) return;
                            this.layout = mode;
                            this.applyLayout();
                            fetch('{{ route("stream.layout.store") }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                body: JSON.stringify({ layout: mode }),
                            }).catch(function() {});
                        },
                        applyLayout() {
                            this.$el.setAttribute('data-stream-layout', this.layout);
                            if (this.layout === 'list') {
                                this.$el.querySelectorAll('[data-stream-card][data-expanded]').forEach(function(card) {
                                    card.removeAttribute('data-expanded');
                                    var btn = card.querySelector('.stream-card-expand');
                                    if (btn) btn.textContent = 'Read more';
                                });
                            }
                            this.$nextTick(function() { streamLayoutCheckOverflow(); });
                        },
                    };
                }
                function streamLayoutCheckOverflow() {
                    document.querySelectorAll('[data-stream-card]').forEach(function(card) {
                        var btn = card.querySelector('.stream-card-expand');
                        if (!btn) return;
                        var isGrid = card.closest('[data-stream-layout="grid"]');
                        if (!isGrid) { btn.style.display = 'none'; card.removeAttribute('data-expanded'); btn.textContent = 'Read more'; return; }
                        if (card.hasAttribute('data-expanded')) { btn.style.display = 'block'; return; }
                        var overflows = card.scrollHeight > card.clientHeight;
                        btn.style.display = overflows ? 'block' : 'none';
                    });
                }
                </script>
            @endif
        @endpush
@endsection
