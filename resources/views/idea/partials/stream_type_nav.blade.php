@php
    use App\Support\ThoughtTypeNavigation;

    $active = $active ?? 'all';
@endphp
<nav
    data-testid="stream-type-nav"
    aria-label="Stream type"
    class="flex justify-center gap-1 mb-6 flex-wrap"
>
    <a
        href="{{ route('idea.stream') }}"
        class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium transition-colors {{ $active === 'all' ? 'bg-memory-violet/15 text-deep-indigo' : 'text-slate-brand/70 hover:text-deep-indigo' }}"
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
            class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium transition-colors {{ $active === $typeKey ? 'bg-memory-violet/15 text-deep-indigo' : 'text-slate-brand/70 hover:text-deep-indigo' }}"
            @if ($active === $typeKey) aria-current="page" @endif
        >{{ ThoughtTypeNavigation::collectionLabel($typeKey) }}</a>
    @endforeach
</nav>
