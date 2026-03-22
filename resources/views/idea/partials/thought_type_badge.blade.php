@php
    use App\Support\ThoughtTypeNavigation;

    $typeKey = ThoughtTypeNavigation::resolveThoughtToTypeKey($thought);
    $sourceLabel = is_string($thought->source ?? null) && trim($thought->source) !== ''
        ? ucfirst(strtolower(trim($thought->source)))
        : null;
    $fallbackLabel = trim((string) ($fallbackLabel ?? ''));
    $label = $typeKey !== null
        ? ThoughtTypeNavigation::thoughtDisplayLabel($typeKey)
        : ($sourceLabel ?: ($fallbackLabel !== '' ? $fallbackLabel : null));
    $baseClass = trim('text-[10.5px] text-slate-brand/40 '.($class ?? ''));
@endphp
@if ($label !== null && $label !== '')
    @if ($typeKey !== null && ThoughtTypeNavigation::isAvailable($typeKey))
        @php
            $routeName = ThoughtTypeNavigation::routeName($typeKey);
            $href = $routeName !== null ? route($routeName) : null;
        @endphp
        @if ($href !== null)
            <a
                href="{{ $href }}"
                class="thought-type-badge-link inline-block rounded-sm font-medium transition-colors hover:text-memory-violet/90 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/45 focus-visible:ring-offset-1 focus-visible:ring-offset-white {{ $baseClass }}"
            >{{ $label }}</a>
        @else
            <span class="thought-type-badge {{ $baseClass }}">{{ $label }}</span>
        @endif
    @else
        <span class="thought-type-badge {{ $baseClass }}">{{ $label }}</span>
    @endif
@endif
