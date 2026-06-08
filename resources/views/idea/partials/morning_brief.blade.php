@php
    /** @var \App\DataTransferObjects\MorningBriefData $morningBrief */
    $variant = $variant ?? 'hero';
    $compact = $variant === 'sidebar';
@endphp

@if ($compact)
    <section class="ideatub-surface px-3 py-3" aria-label="Quick actions">
        <p class="text-[10px] font-semibold tracking-[0.12em] uppercase text-memory-violet/80 mb-2 px-1">Quick actions</p>
        @if ($morningBrief->hasCards())
            <ul class="space-y-1.5" role="list">
                @foreach ($morningBrief->cards as $card)
                    <li>
                        @if ($card->draftId !== null)
                            <button
                                type="button"
                                class="group flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-left transition hover:bg-memory-violet/5"
                                @click="$dispatch('ideatub-load-draft', { id: '{{ $card->draftId }}' })"
                            >
                                @include('idea.partials.morning_brief_card_inner', ['card' => $card, 'compact' => true])
                            </button>
                        @else
                            <a
                                href="{{ $card->href }}"
                                class="group flex items-center gap-2.5 rounded-xl px-2.5 py-2 transition hover:bg-memory-violet/5"
                            >
                                @include('idea.partials.morning_brief_card_inner', ['card' => $card, 'compact' => true])
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@else
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
@endif
