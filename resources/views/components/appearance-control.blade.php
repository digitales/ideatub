@php
    $appearance = $appearance ?? 'system';
    $compact = $compact ?? false;
@endphp
<div
    @class([
        'appearance-control',
        'ideatub-segment-track w-full' => $compact,
        'ideatub-segment-track' => ! $compact,
    ])
    role="group"
    aria-label="Appearance"
    data-appearance-control
>
    @foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $value => $label)
        <button
            type="button"
            data-appearance-option="{{ $value }}"
            @class([
                $appearance === $value ? 'ideatub-segment-tab-active' : 'ideatub-segment-tab',
                'flex-1 justify-center' => $compact,
            ])
            aria-pressed="{{ $appearance === $value ? 'true' : 'false' }}"
            @if ($compact) aria-label="{{ $label }}" @endif
            onclick="window.ideatubAppearance?.setAppearance('{{ $value }}')"
        >
            @if ($compact)
                <span class="sr-only">{{ $label }}</span>
                @if ($value === 'light')
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
                @elseif ($value === 'dark')
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                @else
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                @endif
            @else
                {{ $label }}
            @endif
        </button>
    @endforeach
</div>
