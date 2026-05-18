@php
    /** @var \App\DataTransferObjects\MorningBriefCardData $card */
    $iconWrapClass = match ($card->kind) {
        'draft' => 'bg-memory-violet/12 text-memory-violet',
        'inbox' => 'bg-neural-teal/12 text-neural-teal',
        'revisit' => 'bg-amber-500/10 text-amber-700',
        'project' => 'bg-memory-violet/12 text-memory-violet',
        default => 'bg-memory-violet/12 text-memory-violet',
    };
@endphp
<span class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center {{ $iconWrapClass }}" aria-hidden="true">
    @if ($card->kind === 'draft')
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
    @elseif ($card->kind === 'inbox')
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
    @elseif ($card->kind === 'revisit')
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
    @else
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
    @endif
</span>
<div class="min-w-0 flex-1">
    <span class="text-[10px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80">{{ $card->label }}</span>
    <p class="mt-0.5 text-sm font-medium text-deep-indigo leading-snug line-clamp-2 group-hover:text-memory-violet transition-colors">{{ $card->title }}</p>
    @if ($card->subtitle)
        <p class="mt-0.5 text-[11px] text-slate-brand/60">{{ $card->subtitle }}</p>
    @endif
</div>
@if ($card->draftId === null)
    <svg class="flex-shrink-0 w-4 h-4 mt-0.5 text-slate-brand/30 group-hover:text-memory-violet/60 transition-colors" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
@endif
