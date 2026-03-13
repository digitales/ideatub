@extends('layouts.idea') 

@section('title', $tag ? 'Tag: ' . e($tag) . ' — IdeaTub' : 'Stream — IdeaTub')

    @section('content')
        <div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
            <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-6">
                @if($tag)
                    Tag: {{e($tag)}}
                @else
                    Your Stream
                @endif
            </h1>

            @if($tag)
                <p class="text-center mb-4">
                    <a href="{{route('idea.stream')}}" class="text-[12px] font-medium text-memory-violet hover:underline">All
                        thoughts</a>
                </p>
            @endif

            @if($thoughts->isEmpty())
                <div class="rounded-xl border border-memory-violet/10 bg-white/50 px-4 py-8 text-center text-sm text-slate-brand/50">
                    @if($tag)
                        No thoughts with tag ‘{{e($tag)}}’. <a href="{{route('idea.stream')}}" class="text-memory-violet hover:underline">All thoughts</a>
                    @else
                        No thoughts yet. <a href="{{route('idea.index')}}" class="text-memory-violet hover:underline">Capture
                            one from the home page</a>.
                    @endif
                </div>
            @else
                <p class="text-[11px] text-slate-brand/40 mb-2" id="stream-count-line">
                    Showing <span id="stream-showing-count">{{$thoughts->count()}}</span> of <span id="stream-total-count">{{$thoughts->total()}}</span> thoughts
                </p>
                <div id="stream-thoughts-list">
                    @include('idea.stream_thoughts', ['thoughts' => $thoughts, 'showFullSections' => (bool) $tag]) 
                </div>
                @if($thoughts->hasMorePages())
                    <div id="stream-load-more-sentinel" class="h-4 mt-4" data-stream-base-url="{{$tagSlug ? route('idea.stream', ['tag' => $tagSlug]) : route('idea.stream')}}" data-stream-total="{{$thoughts->total()}}"></div>
                @endif
            @endif
        </div>

        @push('scripts')
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
        @endpush
    @endsection