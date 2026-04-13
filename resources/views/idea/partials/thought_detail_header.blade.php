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

    @include('idea.partials.thought_detail_actions_row', [
        'thought' => $thought,
        'thoughtDetail' => $thoughtDetail ?? null,
        'editable' => $editable ?? true,
        'projectsToAttachToThought' => $projectsToAttachToThought ?? collect(),
        'thoughtOutgoingLinks' => $thoughtOutgoingLinks ?? collect(),
        'thoughtIncomingLinks' => $thoughtIncomingLinks ?? collect(),
        'linkTargetThoughtOptions' => $linkTargetThoughtOptions ?? collect(),
        'linkTargetThoughtOptionsUsedGlobalFallback' => $linkTargetThoughtOptionsUsedGlobalFallback ?? false,
    ])

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
