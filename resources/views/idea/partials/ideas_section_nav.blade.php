@php
    $active = $active ?? 'ideas';

    $tabClass = fn (bool $isActive): string => $isActive
        ? 'ideatub-segment-tab ideatub-segment-tab-active'
        : 'ideatub-segment-tab';
@endphp
<nav
    data-testid="ideas-section-nav"
    aria-label="Ideas section"
    class="mb-8 overflow-x-auto -mx-1 px-1 pb-1"
>
    <div class="ideatub-segment-track min-w-full sm:min-w-0">
        <a
            href="{{ route('idea.ideas') }}"
            class="inline-flex shrink-0 items-center {{ $tabClass($active === 'ideas') }}"
            @if ($active === 'ideas') aria-current="page" @endif
        >Ideas</a>
        <a
            href="{{ route('idea.revisit') }}"
            class="inline-flex shrink-0 items-center {{ $tabClass($active === 'revisit') }}"
            @if ($active === 'revisit') aria-current="page" @endif
        >Ideas to revisit</a>
        <a
            href="{{ route('idea.completed') }}"
            class="inline-flex shrink-0 items-center {{ $tabClass($active === 'completed') }}"
            @if ($active === 'completed') aria-current="page" @endif
        >Completed ideas</a>
    </div>
</nav>
