@php
    /** @var \App\DataTransferObjects\MorningBriefData $morningBrief */
@endphp
<section class="mb-10 text-left" aria-label="Morning brief">
    <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90 mb-2">Morning brief</p>
    <h1 class="text-3xl font-semibold tracking-tight text-deep-indigo leading-snug mb-1.5">{{ $morningBrief->greeting }}</h1>
    <p class="text-sm text-slate-brand mb-6 max-w-[48ch]">Pick up where you left off, or capture something new below.</p>

    @if ($morningBrief->hasCards())
        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-3" role="list">
            @foreach ($morningBrief->cards as $card)
                <li>
                    @if ($card->draftId !== null)
                        <button
                            type="button"
                            class="group flex w-full items-start gap-3 text-left rounded-2xl bg-white px-4 py-4 ring-1 ring-deep-indigo/[0.06] shadow-[0_1px_3px_rgba(30,37,71,0.04)] transition hover:ring-memory-violet/25 hover:shadow-[0_10px_32px_rgba(109,106,247,0.1)]"
                            @click="$dispatch('ideatub-load-draft', { id: '{{ $card->draftId }}' })"
                        >
                            @include('idea.partials.morning_brief_card_inner', ['card' => $card])
                        </button>
                    @else
                        <a
                            href="{{ $card->href }}"
                            class="group flex items-start gap-3 rounded-2xl bg-white px-4 py-4 ring-1 ring-deep-indigo/[0.06] shadow-[0_1px_3px_rgba(30,37,71,0.04)] transition hover:ring-memory-violet/25 hover:shadow-[0_10px_32px_rgba(109,106,247,0.1)]"
                        >
                            @include('idea.partials.morning_brief_card_inner', ['card' => $card])
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
