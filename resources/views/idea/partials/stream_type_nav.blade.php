@php
    use App\Support\ThoughtTypeNavigation;

    $active = $active ?? 'all';

    $tabClass = fn (bool $isActive): string => $isActive
        ? 'ideatub-segment-tab ideatub-segment-tab-active'
        : 'ideatub-segment-tab';
@endphp
<nav
    data-testid="stream-type-nav"
    aria-label="Stream type"
    class="mb-6 overflow-x-auto -mx-1 px-1 pb-1"
>
    <div class="ideatub-segment-track min-w-full sm:min-w-0">
        <a
            href="{{ route('idea.stream') }}"
            class="inline-flex shrink-0 items-center {{ $tabClass($active === 'all') }}"
            @if ($active === 'all') aria-current="page" @endif
        >All thoughts</a>
        @foreach (ThoughtTypeNavigation::orderedStreamNavTypes() as $typeKey)
            @continue(! ThoughtTypeNavigation::isAvailable($typeKey))

            @php
                $routeName = ThoughtTypeNavigation::routeName($typeKey);
            @endphp

            @continue($routeName === null)

            <a
                href="{{ route($routeName) }}"
                class="inline-flex shrink-0 items-center {{ $tabClass($active === $typeKey) }}"
                @if ($active === $typeKey) aria-current="page" @endif
            >{{ ThoughtTypeNavigation::collectionLabel($typeKey) }}</a>
        @endforeach
    </div>
</nav>
