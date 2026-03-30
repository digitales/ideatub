@php
    $active = $active ?? 'ideas';
@endphp
<nav
    data-testid="ideas-section-nav"
    aria-label="Ideas section"
    class="flex justify-center gap-1 mb-6"
>
    <a
        href="{{ route('idea.ideas') }}"
        class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium transition-colors {{ $active === 'ideas' ? 'bg-memory-violet/15 text-deep-indigo' : 'text-slate-brand/70 hover:text-deep-indigo' }}"
        @if ($active === 'ideas') aria-current="page" @endif
    >Ideas</a>
    <a
        href="{{ route('idea.revisit') }}"
        class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium transition-colors {{ $active === 'revisit' ? 'bg-memory-violet/15 text-deep-indigo' : 'text-slate-brand/70 hover:text-deep-indigo' }}"
        @if ($active === 'revisit') aria-current="page" @endif
    >Ideas to revisit</a>
    <a
        href="{{ route('idea.completed') }}"
        class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium transition-colors {{ $active === 'completed' ? 'bg-memory-violet/15 text-deep-indigo' : 'text-slate-brand/70 hover:text-deep-indigo' }}"
        @if ($active === 'completed') aria-current="page" @endif
    >Completed ideas</a>
</nav>
