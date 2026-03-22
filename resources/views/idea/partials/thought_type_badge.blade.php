@php
    use App\Support\ThoughtTypeNavigation;

    $typeKey = ThoughtTypeNavigation::resolveThoughtToTypeKey($thought);
    $extraClass = trim($class ?? '');
@endphp
@if ($typeKey !== null)
    @php
        $label = ThoughtTypeNavigation::thoughtDisplayLabel($typeKey);
    @endphp
    @if ($label !== '')
        @if (ThoughtTypeNavigation::isAvailable($typeKey))
            @php
                $routeName = ThoughtTypeNavigation::routeName($typeKey);
                $href = $routeName !== null ? route($routeName) : null;
            @endphp
            @if ($href !== null)
                <a
                    href="{{ $href }}"
                    class="thought-type-badge-link inline-block rounded-sm text-[10.5px] font-medium text-slate-brand/40 transition-colors hover:text-memory-violet/90 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/45 focus-visible:ring-offset-1 focus-visible:ring-offset-white {{ $extraClass }}"
                >{{ $label }}</a>
            @else
                <span class="thought-type-badge text-[10.5px] text-slate-brand/40 {{ $extraClass }}">{{ $label }}</span>
            @endif
        @else
            <span class="thought-type-badge text-[10.5px] text-slate-brand/40 {{ $extraClass }}">{{ $label }}</span>
        @endif
    @endif
@endif
