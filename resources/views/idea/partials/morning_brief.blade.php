@php
    /** @var \App\DataTransferObjects\MorningBriefData $morningBrief */
@endphp
<section class="mb-10 text-left" aria-label="Morning brief">
    <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90 mb-2">Morning brief</p>
    <h1 class="text-3xl font-semibold tracking-tight text-deep-indigo leading-snug mb-1.5">{{ $morningBrief->greeting }}</h1>
    <p class="text-sm text-slate-brand mb-6 max-w-[48ch]">Pick up where you left off, or capture something new below.</p>

    @if ($morningBrief->hasCards())
        <ul class="grid grid-cols-1 gap-3 md:grid-cols-2" role="list">
            @foreach ($morningBrief->cards as $card)
                <li>
                    @if ($card->draftId !== null)
                        <button
                            type="button"
                            class="ideatub-surface group flex w-full items-start gap-3 px-4 py-4 text-left transition hover:ring-memory-violet/25 dark:hover:ring-violet-400/30"
                            @click="$dispatch('ideatub-load-draft', { id: '{{ $card->draftId }}' })"
                        >
                            @include('idea.partials.morning_brief_card_inner', ['card' => $card])
                        </button>
                    @else
                        <a
                            href="{{ $card->href }}"
                            class="ideatub-surface group flex items-start gap-3 px-4 py-4 transition hover:ring-memory-violet/25 dark:hover:ring-violet-400/30"
                        >
                            @include('idea.partials.morning_brief_card_inner', ['card' => $card])
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
