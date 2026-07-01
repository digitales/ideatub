@php
    use App\Enums\ThoughtLinkType;
@endphp

@if ($thoughtSuggestedLinks->isNotEmpty())
    <details class="rounded-2xl border border-dashed border-memory-violet/25 bg-white/60 p-4">
        <summary class="cursor-pointer list-none [&::-webkit-details-marker]:hidden select-none">
            <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80">Suggested links</span>
            <span class="ml-2 text-xs font-normal text-slate-brand/60">Semantic neighbors — promote to add an explicit link</span>
        </summary>

        <ul class="mt-4 space-y-3">
            @foreach ($thoughtSuggestedLinks as $suggestion)
                @php $target = $suggestion->toThought; @endphp
                <li class="rounded-xl border border-memory-violet/10 bg-white/80 px-4 py-3 text-sm">
                    <p class="text-deep-indigo break-words">
                        <a href="{{ route('thoughts.show', $target) }}" class="hover:underline">{{ \Illuminate\Support\Str::limit($target->content, 120) }}</a>
                    </p>
                    <p class="text-[10px] uppercase tracking-wider text-slate-brand/50 mt-1">Similarity distance {{ number_format($suggestion->distance, 3) }}</p>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('thoughts.links.store', $thought) }}" class="inline">
                            @csrf
                            <input type="hidden" name="to_thought_id" value="{{ $target->id }}" />
                            <input type="hidden" name="suggestion_id" value="{{ $suggestion->id }}" />
                            <input type="hidden" name="link_type" value="{{ ThoughtLinkType::RelatesTo->value }}" />
                            <button type="submit" class="rounded-lg bg-memory-violet px-3 py-1.5 text-xs font-medium text-white hover:opacity-90">Add link</button>
                        </form>
                        <form method="POST" action="{{ route('thoughts.suggestions.dismiss', [$thought, $suggestion]) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-xs text-slate-brand hover:text-red-600">Dismiss</button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    </details>
@endif
