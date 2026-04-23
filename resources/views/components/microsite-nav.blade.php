@props([
    'items',
    'ariaLabel' => 'Document pages',
])

<nav {{ $attributes->class('mb-6 flex flex-wrap gap-2') }} aria-label="{{ e($ariaLabel) }}">
    @foreach ($items as $item)
        <a href="{{ $item->url }}"
            @if ($item->is_active) aria-current="page" @endif
            @class([
                'text-[12px] font-medium px-2.5 py-1.5 rounded-lg border border-memory-violet/20 transition',
                'bg-memory-violet/10 text-memory-violet' => $item->is_active,
                'text-slate-brand hover:bg-memory-violet/5' => ! $item->is_active,
            ])
        >{{ e($item->label) }}</a>
    @endforeach
</nav>
