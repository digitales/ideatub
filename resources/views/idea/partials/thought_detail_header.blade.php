<div
    class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] @if (isset($thoughtDetail) && $thoughtDetail->isVideoThought()) border-l-[3px] border-l-rose-400/90 @endif"
    @if (isset($thoughtDetail) && $thoughtDetail->isVideoThought()) data-thought-detail-kind="video" @endif
>
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Thought detail</p>

    <div class="flex items-center gap-2 flex-wrap">
        @include('idea.partials.thought_type_badge', [
            'thought' => $thought,
            'class' => 'text-[11px] font-medium uppercase tracking-[0.08em] text-slate-brand/60',
            'fallbackLabel' => 'Thought',
        ])
        <span class="text-[10.5px] text-slate-brand/40">{{ $thought->created_at->diffForHumans() }}</span>
    </div>

    <div class="mt-4">
        @include('idea.partials.thought_tag_row', ['thought' => $thought, 'editable' => $editable ?? true])
    </div>

    @if (isset($thoughtDetail) && $thoughtDetail->isVideoThought())
        <div class="mt-4 pt-4 border-t border-memory-violet/10 space-y-3 text-[12px] text-slate-brand">
            <div>
                <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-rose-600/90 mb-2">Video metadata</p>
                @include('idea.partials.video_metadata_labeled_rows', ['rows' => $thoughtDetail->videoMetadataLabeledRows()])
            </div>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 pt-1">
                @if ($thoughtDetail->videoLatestResearchUrl())
                    <a href="{{ $thoughtDetail->videoLatestResearchUrl() }}" class="font-medium text-memory-violet hover:underline">View research</a>
                @endif
                @if ($thoughtDetail->showFetchTranscriptAction())
                    <form method="POST" action="{{ route('videos.store') }}" class="inline">
                        @csrf
                        <input type="hidden" name="youtube_url" value="{{ $thoughtDetail->videoFetchTranscriptActionUrl() }}">
                        <button type="submit" class="font-medium text-memory-violet hover:underline">Fetch transcript</button>
                    </form>
                @endif
                @if ($thoughtDetail->showVideoResearchPending())
                    <span class="text-slate-brand/75">Research pending</span>
                @endif
                @if ($thoughtDetail->showVideoResearchNowHint())
                    <form method="POST" action="{{ route('videos.store') }}" class="inline">
                        @csrf
                        <input type="hidden" name="youtube_url" value="{{ $thoughtDetail->videoResearchActionUrl() }}">
                        <input type="hidden" name="research_now" value="1">
                        <button type="submit" class="font-medium text-memory-violet hover:underline">Research now</button>
                    </form>
                @endif
                @if ($thoughtDetail->showVideoRerunResearchHint())
                    <form method="POST" action="{{ route('videos.store') }}" class="inline">
                        @csrf
                        <input type="hidden" name="youtube_url" value="{{ $thoughtDetail->videoResearchActionUrl() }}">
                        <input type="hidden" name="research_now" value="1">
                        <button type="submit" class="font-medium text-memory-violet hover:underline">Rerun research</button>
                    </form>
                @endif
            </div>
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
        </div>
    @endif

    @if (($thought->metadata['type'] ?? null) === 'idea' && $thought->isIdeaCompleted())
        <div class="mt-4 pt-4 border-t border-memory-violet/10">
            <form method="POST" action="{{ route('ideas.toggle-completed', $thought) }}" class="inline">
                @csrf
                @method('PATCH')
                <button type="submit" class="text-[12px] font-medium text-neural-teal hover:underline">
                    Mark as incomplete
                </button>
            </form>
        </div>
    @endif
</div>
