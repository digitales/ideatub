{{-- Ideas list: "Your ideas" header, list or empty state, pagination --}}
<div class="flex items-center justify-between mt-9 mb-3.5">
    <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-slate-brand/50">Your ideas</span>
    <span class="text-[11px] text-slate-brand/30">{{ $ideas->total() }} total</span>
</div>

@if ($ideas->isEmpty())
    <div class="rounded-xl border border-memory-violet/10 bg-white/50 px-4 py-8 text-center text-sm text-slate-brand/50">
        No ideas yet. Add one above.
    </div>
@else
    <ul class="space-y-3">
        @foreach ($ideas as $thought)
            @php
                $researchList = $researchByIdea->get($thought->id, collect());
            @endphp
            <li class="rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3 flex items-start gap-3">
                <form method="POST" action="{{ route('ideas.toggle-completed', $thought) }}" class="flex-shrink-0 mt-0.5">
                    @csrf
                    @method('PATCH')
                    <label class="cursor-pointer">
                        <input
                            type="checkbox"
                            class="rounded border-slate-300 text-neural-teal focus:ring-memory-violet/30"
                            {{ $thought->isIdeaCompleted() ? 'checked' : '' }}
                            onchange="this.form.submit()"
                        />
                        <span class="sr-only">Mark as {{ $thought->isIdeaCompleted() ? 'incomplete' : 'complete' }}</span>
                    </label>
                </form>
                <div class="min-w-0 flex-1">
                    <p class="text-sm text-deep-indigo {{ $thought->isIdeaCompleted() ? 'line-through text-slate-brand/70' : '' }}">
                        {{ Str::limit($thought->content, 200) }}
                    </p>
                    <p class="text-[11px] text-slate-brand/50 mt-1">{{ $thought->getLoggedDate() }}</p>
                    @include('idea.partials.thought_tag_row', ['thought' => $thought, 'editable' => true])
                    {{-- Research block --}}
                    <div class="mt-2 pt-2 border-t border-memory-violet/10">
                        @if ($thought->isResearchPending())
                            <p class="text-xs text-slate-brand/70 flex items-center gap-1.5">
                                <span class="inline-block size-3.5 rounded-full border-2 border-neural-teal/50 border-t-neural-teal animate-spin" aria-hidden="true"></span>
                                Researching…
                            </p>
                            @if ($researchList->isNotEmpty())
                                <p class="text-[11px] font-semibold text-slate-brand/60 uppercase tracking-wide mb-1 mt-2">Research</p>
                                @foreach ($researchList as $research)
                                    <div class="text-sm text-slate-brand/80 mb-2">
                                        <p>{{ Str::limit($research->content, 120) }}</p>
                                        <details class="mt-1">
                                            <summary class="text-xs text-neural-teal cursor-pointer hover:underline">View full</summary>
                                            <div class="mt-1 p-2 rounded-lg bg-slate-50/80 text-sm text-deep-indigo whitespace-pre-wrap">{{ $research->content }}</div>
                                        </details>
                                    </div>
                                @endforeach
                            @endif
                        @elseif ($researchList->isEmpty())
                            <form method="POST" action="{{ route('ideas.research', $thought) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs font-medium text-neural-teal hover:underline">
                                    Research this idea
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('ideas.research', $thought) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs font-medium text-neural-teal hover:underline">Regenerate</button>
                            </form>
                            <p class="text-[11px] font-semibold text-slate-brand/60 uppercase tracking-wide mb-1 mt-1">Research</p>
                            @foreach ($researchList as $research)
                                <div class="text-sm text-slate-brand/80 mb-2">
                                    <p>{{ Str::limit($research->content, 120) }}</p>
                                    <details class="mt-1">
                                        <summary class="text-xs text-neural-teal cursor-pointer hover:underline">View full</summary>
                                        <div class="mt-1 p-2 rounded-lg bg-slate-50/80 text-sm text-deep-indigo whitespace-pre-wrap">{{ $research->content }}</div>
                                    </details>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
    @if ($ideas->hasMorePages())
        <div class="mt-4 flex justify-center">
            {{ $ideas->links('pagination.idea') }}
        </div>
    @endif
@endif
