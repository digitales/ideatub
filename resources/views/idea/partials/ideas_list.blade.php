{{-- Ideas list: "Your ideas" header, list or empty state, pagination --}}
<div class="flex items-center justify-between mt-9 mb-3.5">
    <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-slate-brand/50">Your ideas</span>
    <span class="text-[11px] text-slate-brand/30">{{ $ideas->total() }} incomplete</span>
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
            <li data-thought-id="{{ $thought->id }}" class="rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3 flex items-start gap-3">
                <form method="POST" action="{{ route('ideas.toggle-completed', $thought) }}" class="flex-shrink-0 mt-0.5">
                    @csrf
                    @method('PATCH')
                    <label class="cursor-pointer">
                        <input
                            type="checkbox"
                            class="rounded border-slate-300 text-neural-teal focus:ring-memory-violet/30"
                            onchange="this.form.submit()"
                        />
                        <span class="sr-only">Mark as complete</span>
                    </label>
                </form>
                <div class="min-w-0 flex-1 relative">
                    <div class="absolute top-0 right-0 z-10">
                        @include('idea.partials.thought_card_actions', ['thought' => $thought, 'editable' => auth()->check() && auth()->id() === $thought->user_id])
                    </div>
                    <div class="pr-8">
                        @include('idea.partials.editable_thought_content', [
                            'thought' => $thought,
                            'editable' => auth()->check() && auth()->id() === $thought->user_id,
                            'displayClass' => 'text-sm text-deep-indigo whitespace-pre-line mb-0 ',
                            'previewMaxLength' => 200,
                        ])
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
                                        <a href="{{ route('idea.research.show', $research) . '?from=ideas' }}" class="text-xs font-medium text-neural-teal hover:underline">View formatted</a>
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
                                    <a href="{{ route('idea.research.show', $research) . '?from=ideas' }}" class="text-xs font-medium text-neural-teal hover:underline">View formatted</a>
                                </div>
                            @endforeach
                        @endif
                    </div>
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
