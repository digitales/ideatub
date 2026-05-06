@php
    $twoColumn = $twoColumn ?? false;
@endphp

<div class="max-w-6xl mx-auto px-6 md:px-8 pt-16 pb-24 space-y-6">
    @isset($header)
        {{ $header }}
    @endisset

    @if ($twoColumn)
        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)] lg:items-start">
            <div class="space-y-6 min-w-0" data-thought-detail-main>
                @isset($main)
                    {{ $main }}
                @endisset
            </div>

            <div class="space-y-6">
                @isset($sidebar)
                    {{ $sidebar }}
                @endisset
            </div>
        </div>
    @else
        <div class="space-y-6">
            @isset($main)
                {{ $main }}
            @endisset
        </div>
    @endif

    @isset($footer)
        {{ $footer }}
    @endisset
</div>
