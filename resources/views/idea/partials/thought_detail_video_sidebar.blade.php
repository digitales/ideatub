@php
    $videoSidebarHasPostActions =
        $thoughtDetail->showFetchTranscriptAction()
        || $thoughtDetail->showVideoResearchNowHint()
        || $thoughtDetail->showVideoRerunResearchHint()
        || ($thoughtDetail->showAddTranscriptForm() && ($editable ?? true));
@endphp

<aside
    class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)]"
    data-thought-detail-sidebar="video"
>
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-rose-600/90 mb-4">Video metadata</p>

    <div class="space-y-3 text-[12px] text-slate-brand">
        @include('idea.partials.video_metadata_labeled_rows', ['rows' => $thoughtDetail->videoMetadataLabeledRows()])
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1">
        @if ($thoughtDetail->videoLatestResearchUrl())
            <a href="{{ $thoughtDetail->videoLatestResearchUrl() }}" class="font-medium text-memory-violet hover:underline">View research</a>
        @endif
        @if ($thoughtDetail->showVideoResearchPending())
            <span class="text-slate-brand/75">Research pending</span>
        @endif
    </div>

    @if ($videoSidebarHasPostActions)
        <div class="mt-5 pt-4 border-t border-memory-violet/10 space-y-2">
            <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Actions</p>
            @if ($thoughtDetail->showFetchTranscriptAction())
                <form method="POST" action="{{ route('videos.store') }}" class="block" x-data="{ submitting: false }" @submit="submitting = true">
                    @csrf
                    <input type="hidden" name="youtube_url" value="{{ $thoughtDetail->videoFetchTranscriptActionUrl() }}">
                    @isset($videoCaptureReturnThoughtId)
                        <input type="hidden" name="return_thought_id" value="{{ $videoCaptureReturnThoughtId }}">
                    @endisset
                    <button type="submit" :disabled="submitting" class="w-full text-left px-3 py-2 text-[12px] font-medium text-memory-violet border border-memory-violet/30 rounded-lg hover:bg-memory-violet/5 transition-colors disabled:opacity-50">
                        Fetch transcript
                    </button>
                </form>
            @endif
            @if ($thoughtDetail->showVideoResearchNowHint())
                <form method="POST" action="{{ route('videos.store') }}" class="block" x-data="{ submitting: false }" @submit="submitting = true">
                    @csrf
                    <input type="hidden" name="youtube_url" value="{{ $thoughtDetail->videoResearchActionUrl() }}">
                    <input type="hidden" name="research_now" value="1">
                    @isset($videoCaptureReturnThoughtId)
                        <input type="hidden" name="return_thought_id" value="{{ $videoCaptureReturnThoughtId }}">
                    @endisset
                    <button type="submit" :disabled="submitting" class="w-full text-left px-3 py-2 text-[12px] font-medium text-memory-violet border border-memory-violet/30 rounded-lg hover:bg-memory-violet/5 transition-colors disabled:opacity-50">
                        Research now
                    </button>
                </form>
            @endif
            @if ($thoughtDetail->showVideoRerunResearchHint())
                <form method="POST" action="{{ route('videos.store') }}" class="block" x-data="{ submitting: false }" @submit="submitting = true">
                    @csrf
                    <input type="hidden" name="youtube_url" value="{{ $thoughtDetail->videoResearchActionUrl() }}">
                    <input type="hidden" name="research_now" value="1">
                    @isset($videoCaptureReturnThoughtId)
                        <input type="hidden" name="return_thought_id" value="{{ $videoCaptureReturnThoughtId }}">
                    @endisset
                    <button type="submit" :disabled="submitting" class="w-full text-left px-3 py-2 text-[12px] font-medium text-memory-violet border border-memory-violet/30 rounded-lg hover:bg-memory-violet/5 transition-colors disabled:opacity-50">
                        Rerun research
                    </button>
                </form>
            @endif
            @if ($thoughtDetail->showAddTranscriptForm() && ($editable ?? true))
                <div class="mt-4 pt-4 border-t border-memory-violet/10">
                    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-2">Add transcript</p>
                    <p class="text-xs text-deep-indigo leading-relaxed mb-3">
                        Paste the transcript from YouTube (Show transcript) if automatic fetch is unavailable or you prefer your own copy.
                    </p>
                    <form method="POST" action="{{ route('videos.store') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="youtube_url" value="{{ $thoughtDetail->videoFetchTranscriptActionUrl() }}">
                        @isset($videoCaptureReturnThoughtId)
                            <input type="hidden" name="return_thought_id" value="{{ $videoCaptureReturnThoughtId }}">
                        @endisset
                        <label class="block">
                            <span class="text-[11px] font-medium text-memory-violet/90">Transcript</span>
                            <textarea
                                name="transcript"
                                rows="6"
                                required
                                class="mt-1 w-full rounded-lg border border-memory-violet/20 bg-white/90 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/40 focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50 resize-y min-h-[8rem]"
                                placeholder="Paste the full transcript…"
                            >{{ old('transcript') }}</textarea>
                        </label>
                        @error('transcript')
                            <p class="text-xs text-red-500">{{ $message }}</p>
                        @enderror
                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg bg-memory-violet px-3 py-2 text-xs font-medium text-white shadow-sm hover:bg-memory-violet/90 focus:outline-none focus:ring-2 focus:ring-memory-violet/40"
                        >
                            Save transcript
                        </button>
                    </form>
                </div>
            @endif
        </div>
    @endif

    @if ($thoughtDetail->relatedEmailCard())
        @php $relatedEmail = $thoughtDetail->relatedEmailCard(); @endphp
        <div class="mt-4 pt-4 border-t border-memory-violet/10">
            <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-2">Related email</p>
            <p class="text-[13px] font-semibold text-deep-indigo">{{ $relatedEmail['subject'] }}</p>
            <p class="text-[12px] text-slate-brand mt-0.5">{{ $relatedEmail['sender'] }}</p>
            <p class="mt-2">
                <a href="{{ $relatedEmail['url'] }}" class="text-[12px] font-medium text-memory-violet hover:underline">View email</a>
            </p>
        </div>
    @endif
</aside>
