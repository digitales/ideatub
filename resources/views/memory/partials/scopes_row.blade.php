@php
    $badge = $row['badge'] ?? null;
    $statusBadgeClass = match ($badge) {
        'Updating' => 'border-teal-300/60 bg-teal-50 text-teal-700',
        'Fallback' => 'border-amber-300/70 bg-amber-50 text-amber-700',
        default => null,
    };
    $freshness = $row['freshness'] ?? null;
    $freshnessBadgeClass = match ($freshness) {
        'fresh' => 'border-neural-teal/30 bg-neural-teal/15 text-neural-teal',
        'degraded' => 'border-amber-200/80 bg-amber-50 text-amber-900',
        'stale' => 'border-slate-200 bg-slate-100 text-slate-600',
        default => 'border-slate-200 bg-slate-50 text-slate-600',
    };
    $refreshed = $row['refreshed'] ?? null;
    $depth = (int) ($row['depth'] ?? 0);
    $scopeCellClass = $depth === 1 ? 'pl-8 border-l border-deep-indigo/[0.06]' : '';
@endphp

<tr class="group transition-colors hover:bg-memory-violet/[0.03]">
    <td class="py-3 pr-4 align-middle {{ $scopeCellClass }}">
        @if (! empty($row['href']))
            <a
                href="{{ $row['href'] }}"
                aria-label="{{ $row['aria_label'] }}"
                class="font-medium text-deep-indigo transition-colors group-hover:text-memory-violet"
            >
                {{ $row['title'] }}
            </a>
        @else
            <span aria-label="{{ $row['aria_label'] }}" class="font-medium text-deep-indigo">
                {{ $row['title'] }}
            </span>
        @endif
        @if (! empty($row['stream_href']))
            <span class="text-slate-brand/40 mx-1.5" aria-hidden="true">·</span>
            <a
                href="{{ $row['stream_href'] }}"
                class="text-sm text-memory-violet transition-colors hover:text-memory-violet/80"
            >
                Stream
            </a>
        @endif
        @if ($freshness !== null)
            <div class="mt-1 sm:hidden">
                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium capitalize {{ $freshnessBadgeClass }}">
                    {{ $freshness }}
                </span>
            </div>
        @endif
    </td>
    <td class="hidden py-3 pr-4 align-middle sm:table-cell">
        @if ($freshness !== null)
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize {{ $freshnessBadgeClass }}">
                {{ $freshness }}
            </span>
        @else
            <span class="text-sm text-slate-brand/50">—</span>
        @endif
    </td>
    <td class="py-3 pr-4 align-middle">
        @if ($badge !== null && $statusBadgeClass !== null)
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $statusBadgeClass }}">
                {{ $badge }}
            </span>
        @else
            <span class="text-sm text-slate-brand/50">—</span>
        @endif
    </td>
    <td class="py-3 text-right align-middle tabular-nums">
        @if ($refreshed !== null)
            <span class="text-sm text-slate-brand">{{ $refreshed }}</span>
        @else
            <span class="text-sm text-slate-brand/50">—</span>
        @endif
    </td>
</tr>
