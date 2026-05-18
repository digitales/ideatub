@php
    /** @var \App\DataTransferObjects\MorningBriefData $morningBrief */
@endphp
<section class="mb-8 text-left" aria-label="Morning brief">
    <p class="text-[11px] font-semibold tracking-[0.12em] uppercase text-memory-violet mb-2">Morning brief</p>
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-1">{{ $morningBrief->greeting }}</h1>
    <p class="text-sm text-slate-brand mb-5">Pick up where you left off, or capture something new below.</p>

    @if ($morningBrief->hasCards())
        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2.5" role="list">
            @foreach ($morningBrief->cards as $card)
                <li>
                    @if ($card->draftId !== null)
                        <button
                            type="button"
                            class="group flex w-full items-start gap-3 text-left rounded-xl border border-memory-violet/15 bg-white/80 backdrop-blur px-4 py-3.5 shadow-[0_2px_16px_rgba(109,106,247,0.06)] hover:border-memory-violet/30 hover:shadow-[0_4px_24px_rgba(109,106,247,0.1)] transition"
                            @click="$dispatch('ideatub-load-draft', { id: '{{ $card->draftId }}' })"
                        >
                            @include('idea.partials.morning_brief_card_inner', ['card' => $card])
                        </button>
                    @else
                        <a
                            href="{{ $card->href }}"
                            class="group flex items-start gap-3 rounded-xl border border-memory-violet/15 bg-white/80 backdrop-blur px-4 py-3.5 shadow-[0_2px_16px_rgba(109,106,247,0.06)] hover:border-memory-violet/30 hover:shadow-[0_4px_24px_rgba(109,106,247,0.1)] transition"
                        >
                            @include('idea.partials.morning_brief_card_inner', ['card' => $card])
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
