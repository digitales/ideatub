@extends('layouts.idea')

@section('title', 'Ideas — IdeaTub')

@section('content')
<div class="mx-auto w-full max-w-7xl px-6 pt-10 pb-20">

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
    @if (session('warning'))
        <div class="mb-6 rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-700">
            {{ session('warning') }}
        </div>
    @endif

    @include('idea.partials.page_shell_header', [
        'eyebrow' => 'Capture',
        'title' => 'Ideas',
        'subtitle' => 'Quick notes and prompts to research later. Mark complete when done.',
    ])

    @include('idea.partials.ideas_section_nav', ['active' => 'ideas'])

    @php
        $ideasComposerBody = old('youtube_url', old('content', ''));
        $ideasComposerVideoHint = filled(old('youtube_url'));
    @endphp

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] lg:items-start">
        {{-- Add idea / smart YouTube video composer --}}
        <aside class="min-w-0 lg:sticky lg:top-24">
            <div
                class="ideatub-surface p-5"
                x-data="ideatubIdeasComposer({
                    initialBody: @js($ideasComposerBody),
                    forceVideoMode: @json($ideasComposerVideoHint),
                    debounceMs: 380,
                    ideaAction: @js(route('ideas.store')),
                    videoAction: @js(route('videos.store')),
                })"
                data-testid="ideas-composer"
                data-ideas-composer-debounce="380"
                data-videos-store-url="{{ route('videos.store') }}"
            >
                <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Add idea</h2>
                <form method="POST" :action="formAction()" class="space-y-3">
                    @csrf
                    <textarea
                        x-model="body"
                        :name="fieldName()"
                        id="ideas-composer-body"
                        rows="4"
                        required
                        :placeholder="bodyPlaceholder()"
                        class="ideatub-input w-full resize-none"
                    ></textarea>
                    @error('content')
                        <p class="text-xs text-red-500">{{ $message }}</p>
                    @enderror
                    @error('youtube_url')
                        <p class="text-xs text-red-500">{{ $message }}</p>
                    @enderror
                    <div x-show="videoMode" x-cloak class="space-y-3 rounded-xl border border-memory-violet/15 bg-memory-violet/5 px-3 py-3">
                        <p class="text-xs text-deep-indigo leading-6" data-testid="ideas-composer-video-confirmation">
                            This will be saved as a <span class="font-medium">video thought</span> (not a regular idea). You can paste an optional transcript below; if you leave it empty, we will try to fetch the transcript from YouTube.
                        </p>
                        <label class="block">
                            <span class="text-[11px] font-medium text-memory-violet/90">Transcript (optional)</span>
                            <textarea
                                name="transcript"
                                id="ideas-composer-transcript"
                                rows="4"
                                placeholder="Paste a transcript if you already have one…"
                                class="ideatub-input mt-1 w-full resize-none"
                            >{{ old('transcript') }}</textarea>
                        </label>
                        <label class="flex items-start gap-2 text-[11px] text-slate-brand/80">
                            <input type="checkbox" name="research_now" value="1" @checked(old('research_now')) class="mt-0.5 rounded border-slate-300 text-memory-violet" />
                            <span>
                                <span class="font-medium text-deep-indigo">Research now</span>
                                <span class="block text-slate-brand/60">Video research runs after the transcript is ready.</span>
                            </span>
                        </label>
                    </div>
                    <div class="flex flex-wrap items-center gap-3" x-show="!videoMode">
                        <label class="text-[11px] text-slate-brand/70">
                            Logged date (optional):
                            <input
                                type="date"
                                name="logged_date"
                                :disabled="videoMode"
                                value="{{ old('logged_date', now()->toDateString()) }}"
                                class="ml-1 rounded-md border border-slate-200 px-2 py-1 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 disabled:opacity-50 disabled:cursor-not-allowed"
                            />
                        </label>
                        <button
                            type="submit"
                            class="ideatub-gradient-btn text-xs font-medium px-4 py-1.5 rounded-lg"
                        >
                            <span x-text="primarySubmitLabel()">Save idea</span>
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-3" x-show="videoMode" x-cloak>
                        <button
                            type="submit"
                            class="ideatub-gradient-btn text-xs font-medium px-4 py-1.5 rounded-lg"
                        >
                            <span x-text="primarySubmitLabel()">Save video</span>
                        </button>
                    </div>
                    @if ($researchAutoRunEligible ?? false)
                        <p x-show="!videoMode" class="text-[11px] text-slate-brand/50">Your default research skill runs automatically when you save.</p>
                    @endif
                </form>

                <details class="group mt-4 rounded-xl border border-memory-violet/10 bg-memory-violet/[0.04] px-3 py-2.5 open:pb-3">
                    <summary class="cursor-pointer text-sm font-medium text-deep-indigo marker:content-none [&::-webkit-details-marker]:hidden flex items-center justify-between gap-2">
                        <span>Save and start research</span>
                        <svg class="size-4 shrink-0 text-slate-brand/50 transition group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </summary>
                    <form method="POST" action="{{ route('ideas.research-new') }}" class="mt-3 space-y-3">
                        @csrf
                        <label class="block">
                            <span class="text-[11px] font-medium text-slate-brand/70">Idea to research</span>
                            <input
                                type="text"
                                name="content"
                                value="{{ old('content') }}"
                                placeholder="Research this idea: …"
                                class="ideatub-input mt-1 w-full"
                            />
                        </label>
                        <label class="block">
                            <span class="text-[11px] font-medium text-slate-brand/70">Research skill</span>
                            <select
                                name="research_skill_id"
                                class="ideatub-input mt-1 w-full"
                            >
                                <option value="">Default skill</option>
                                @foreach (($manualResearchSkills ?? collect()) as $skill)
                                    <option value="{{ $skill->id }}" @selected((string) old('research_skill_id') === (string) $skill->id)>
                                        {{ $skill->name }}@if($skill->is_default) (default)@endif
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <button
                            type="submit"
                            class="ideatub-gradient-btn w-full text-xs font-medium px-4 py-2 rounded-lg"
                        >
                            Save + research
                        </button>
                    </form>
                    @error('research_skill_id')
                        <p class="mt-2 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </details>
            </div>
        </aside>

        {{-- Ideas list --}}
        <div
            id="ideas-list-container"
            class="min-w-0"
            data-ideas-refetch-url="{{ route('idea.ideas') }}"
            data-ideas-since="{{ $ideas->isEmpty() ? '' : $ideas->first()->created_at->toIso8601String() }}"
        >
            @include('idea.partials.ideas_list', ['ideas' => $ideas, 'ideaRows' => $ideaRows])
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    function extractYouTubeVideoId(url) {
        var u = String(url).trim();
        if (!u) {
            return null;
        }
        var m = u.match(/[?&]v=([a-zA-Z0-9_-]{11})(?:[^a-zA-Z0-9_-]|$)/);
        if (m) {
            return m[1];
        }
        m = u.match(/(?:^|\/\/)(?:www\.)?youtu\.be\/([a-zA-Z0-9_-]{11})(?:[^a-zA-Z0-9_-]|$)/i);
        if (m) {
            return m[1];
        }
        m = u.match(/youtube\.com\/(?:embed|shorts|live)\/([a-zA-Z0-9_-]{11})(?:[^a-zA-Z0-9_-]|$)/i);
        if (m) {
            return m[1];
        }
        return null;
    }
    function isLoneYouTubeUrl(raw) {
        var t = String(raw).trim();
        if (!t) {
            return false;
        }
        if (/\n/.test(t) || /\r/.test(t)) {
            return false;
        }
        if (/\s/.test(t)) {
            return false;
        }
        return extractYouTubeVideoId(t) !== null;
    }
    window.ideatubIdeasComposer = function (cfg) {
        return {
            body: cfg.initialBody || '',
            videoMode: false,
            debounceMs: typeof cfg.debounceMs === 'number' ? cfg.debounceMs : 380,
            ideaAction: cfg.ideaAction,
            videoAction: cfg.videoAction,
            _timer: null,
            init: function () {
                var self = this;
                if (cfg.forceVideoMode) {
                    this.videoMode = true;
                } else if (isLoneYouTubeUrl(this.body)) {
                    this.videoMode = true;
                }
                this.$watch('body', function () {
                    self.scheduleDetect();
                });
            },
            scheduleDetect: function () {
                var self = this;
                if (this._timer) {
                    clearTimeout(this._timer);
                }
                this._timer = setTimeout(function () {
                    self.runDetect();
                }, this.debounceMs);
            },
            runDetect: function () {
                var lone = isLoneYouTubeUrl(this.body);
                if (lone) {
                    this.videoMode = true;
                    return;
                }
                if (this.videoMode) {
                    var t = String(this.body).trim();
                    if (t === '' || !isLoneYouTubeUrl(this.body)) {
                        this.videoMode = false;
                    }
                }
            },
            formAction: function () {
                return this.videoMode ? this.videoAction : this.ideaAction;
            },
            fieldName: function () {
                return this.videoMode ? 'youtube_url' : 'content';
            },
            bodyPlaceholder: function () {
                return this.videoMode ? 'Paste a YouTube link…' : "What's the idea?";
            },
            primarySubmitLabel: function () {
                return this.videoMode ? 'Save video' : 'Save idea';
            },
        };
    };
})();
</script>
@endpush

@if(!$ideas->isEmpty())
@push('scripts')
<script>
(function() {
    var container = document.getElementById('ideas-list-container');
    if (!container || !window.ideatub || !window.ideatub.realtime) return;
    var cfg = window.ideatub.realtime;
    var refetchUrl = container.getAttribute('data-ideas-refetch-url');
    if (!refetchUrl) return;
    function refetchIdeas() {
        fetch(refetchUrl, { method: 'GET', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.html) container.innerHTML = data.html;
                if (data.latest_created_at) container.setAttribute('data-ideas-since', data.latest_created_at);
            })
            .catch(function() {});
    }
    if (cfg.driver === 'polling' || !cfg.reverb_key) {
        setInterval(function() {
            var since = container.getAttribute('data-ideas-since');
            if (!since || !cfg.realtime_check_url) return;
            fetch(cfg.realtime_check_url + '?since=' + encodeURIComponent(since), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(data) { if (data.has_new) refetchIdeas(); })
                .catch(function() {});
        }, 20000);
    } else if (cfg.driver === 'reverb' && cfg.reverb_key && cfg.user_id) {
        function trySubscribe() {
            if (!window.Echo) { setTimeout(trySubscribe, 100); return; }
            try {
                window.Echo.private('App.Models.User.' + cfg.user_id).listen('.ThoughtCreated', refetchIdeas);
            } catch (e) { console.warn('Echo subscribe failed:', e); }
        }
        trySubscribe();
    }
})();
</script>
@endpush
@endif

@endsection
