@php
    $eyebrow = $eyebrow ?? null;
    $title = $title ?? '';
    $subtitle = $subtitle ?? null;
    $centered = $centered ?? false;
    $alignClass = $centered ? 'text-center items-center' : 'text-left items-end';
    $subtitleClass = $centered ? 'mx-auto' : '';
@endphp
<header class="mb-8 {{ $centered ? 'text-center' : '' }}">
    @if ($eyebrow)
        <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90 mb-2 {{ $centered ? 'text-center' : '' }}">{{ $eyebrow }}</p>
    @endif
    <div class="flex flex-wrap justify-between gap-4 {{ $alignClass }}">
        <div class="min-w-0 {{ $centered ? 'w-full' : '' }}">
            <h1 class="text-3xl font-semibold tracking-tight text-deep-indigo text-balance">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-1.5 text-sm text-slate-brand max-w-[48ch] {{ $subtitleClass }}">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex shrink-0 items-center gap-2">
                {!! $actions !!}
            </div>
        @endisset
    </div>
</header>
