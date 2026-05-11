@extends('layouts.idea')

@php
    use App\Support\TagSlug;
    use App\Support\ThoughtTypeNavigation;
    $__collectionKey = $streamCollectionKey ?? ((isset($streamJira) && $streamJira) ? 'jira' : null);
    $__typedStreamRouteName = $__collectionKey ? ThoughtTypeNavigation::routeName($__collectionKey) : null;
@endphp

@section('title', $__collectionKey ? ThoughtTypeNavigation::documentTitle($__collectionKey) : ($tag ? 'Tag: ' . e($tag) . ' — IdeaTub' : 'Stream — IdeaTub'))

@section('content')
        <div data-stream-layout="{{ $streamLayout }}" class="mx-auto px-6 pt-16 pb-24 {{ $streamLayout === 'grid' ? 'max-w-[1400px]' : 'max-w-[600px]' }}">
            <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-6">
                @if($__collectionKey)
                    {{ ThoughtTypeNavigation::collectionLabel($__collectionKey) }}
                @elseif($tag)
                    Tag: {{e($tag)}}
                @else
                    Your Stream
                @endif
            </h1>

            @if (! $tag)
                @include('idea.partials.stream_type_nav', ['active' => $__collectionKey ?? 'all'])
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
                <div class="rounded-xl border border-memory-violet/10 bg-white/50 px-4 py-8 text-center text-sm text-slate-brand/50">
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
                    @elseif($tag)
                        No thoughts with tag ‘{{e($tag)}}’. <a href="{{route('idea.stream')}}" class="text-memory-violet hover:underline">All thoughts</a>
                    @else
                        No thoughts yet. <a href="{{route('idea.index')}}" class="text-memory-violet hover:underline">Capture
                            one from the home page</a>.
                    @endif
                </div>
            @else
                <div x-data="streamLayout('{{ $streamLayout }}')" x-init="applyLayout()">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[11px] text-slate-brand/40" id="stream-count-line">
                            Showing <span id="stream-showing-count">{{$thoughts->count()}}</span> of <span id="stream-total-count">{{$thoughts->total()}}</span> thoughts
                        </p>
                        <div class="flex items-center gap-1">
                            <button
                                type="button"
                                data-testid="layout-toggle-list"
                                @click="setLayout('list')"
                                :class="layout === 'list' ? 'text-memory-violet' : 'text-slate-brand/40 hover:text-slate-brand'"
                                class="p-1 rounded transition-colors"
                                aria-label="List layout"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                data-testid="layout-toggle-grid"
                                @click="setLayout('grid')"
                                :class="layout === 'grid' ? 'text-memory-violet' : 'text-slate-brand/40 hover:text-slate-brand'"
                                class="p-1 rounded transition-colors"
                                aria-label="Grid layout"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <rect x="3" y="3" width="7" height="7" rx="1" />
                                    <rect x="14" y="3" width="7" height="7" rx="1" />
                                    <rect x="3" y="14" width="7" height="7" rx="1" />
                                    <rect x="14" y="14" width="7" height="7" rx="1" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div id="stream-thoughts-list"
                        :class="layout === 'grid' ? 'grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-2' : ''"
                        data-stream-refetch-url="{{ $__typedStreamRouteName ? route($__typedStreamRouteName) : ($tagSlug ? route('idea.stream', ['tag' => $tagSlug]) : route('idea.stream')) }}?page=1"
                        data-stream-since="{{ $streamSince }}">
                        @include('idea.stream_thoughts', ['cards' => $cards])
                    </div>
                    @if($thoughts->hasMorePages())
                        <div id="stream-load-more-sentinel" class="h-4 mt-4" data-stream-base-url="{{ $__typedStreamRouteName ? route($__typedStreamRouteName) : ($tagSlug ? route('idea.stream', ['tag' => $tagSlug]) : route('idea.stream')) }}" data-stream-total="{{$thoughts->total()}}"></div>
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
                                if (data.html) list.innerHTML = data.html;
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
            @endif
            @if(!$thoughts->isEmpty() && $thoughts->hasMorePages())
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
                            });
                        },
                        applyLayout() {
                            var container = this.$el.closest('[data-stream-layout]');
                            if (!container) return;
                            container.setAttribute('data-stream-layout', this.layout);
                            if (this.layout === 'grid') {
                                container.classList.remove('max-w-[600px]');
                                container.classList.add('max-w-[1400px]');
                            } else {
                                container.classList.remove('max-w-[1400px]');
                                container.classList.add('max-w-[600px]');
                            }
                        },
                    };
                }
                </script>
                <style>
                [data-stream-layout="grid"] [data-stream-card] {
                    max-height: 200px;
                    overflow: hidden;
                    position: relative;
                }
                [data-stream-layout="grid"] [data-stream-card]::after {
                    content: '';
                    position: absolute;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    height: 2rem;
                    background: linear-gradient(to top, rgba(255,255,255,0.9), transparent);
                    pointer-events: none;
                }
                [data-stream-layout="grid"] [data-stream-card] .mb-2:last-child {
                    margin-bottom: 0;
                }
                </style>
        @endpush
@endsection
