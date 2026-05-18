@php
    use App\Support\ThoughtTypeNavigation;

    $active = $active ?? 'all';

    $tabClass = function (bool $isActive): string {
        return $isActive
            ? 'bg-white text-deep-indigo shadow-sm ring-1 ring-deep-indigo/5'
            : 'text-slate-brand/75 hover:text-deep-indigo hover:bg-white/50';
    };
@endphp
<nav
    data-testid="stream-type-nav"
    aria-label="Stream type"
    class="mb-6 overflow-x-auto -mx-1 px-1 pb-1"
>
    <div class="inline-flex min-w-full sm:min-w-0 items-center gap-0.5 rounded-2xl bg-white/55 p-1 ring-1 ring-deep-indigo/[0.06] backdrop-blur-sm">
        <a
            href="{{ route('idea.stream') }}"
            class="inline-flex shrink-0 items-center rounded-xl px-3 py-2 text-sm font-medium transition {{ $tabClass($active === 'all') }}"
            @if ($active === 'all') aria-current="page" @endif
        >All thoughts</a>
        @foreach (ThoughtTypeNavigation::orderedNavTypes() as $typeKey)
            @continue(! ThoughtTypeNavigation::isAvailable($typeKey))

            @php
                $routeName = ThoughtTypeNavigation::routeName($typeKey);
            @endphp

            @continue($routeName === null)

            <a
                href="{{ route($routeName) }}"
                class="inline-flex shrink-0 items-center rounded-xl px-3 py-2 text-sm font-medium transition {{ $tabClass($active === $typeKey) }}"
                @if ($active === $typeKey) aria-current="page" @endif
            >{{ ThoughtTypeNavigation::collectionLabel($typeKey) }}</a>
        @endforeach
    </div>
</nav>
