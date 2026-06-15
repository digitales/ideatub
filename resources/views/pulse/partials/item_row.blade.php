@php
    $severity = $item->severity ?? null;
    $badgeClass = match ($severity) {
        'high' => 'border-red-200 bg-red-50 text-red-700',
        'medium' => 'border-amber-200 bg-amber-50 text-amber-800',
        'low' => 'border-slate-200 bg-slate-50 text-slate-600',
        default => 'border-memory-violet/20 bg-memory-violet/5 text-memory-violet',
    };
@endphp
<li class="rounded-xl border border-memory-violet/15 bg-white/80 backdrop-blur px-4 py-3 shadow-[0_2px_12px_rgba(109,106,247,0.05)]">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                @if ($severity !== null)
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.08em] {{ $badgeClass }}">
                        {{ $severity }}
                    </span>
                @endif
                <span class="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-brand/70">{{ str_replace('_', ' ', $item->kind) }}</span>
            </div>
            <a href="{{ $item->href }}" class="mt-1 block text-sm font-medium text-deep-indigo hover:text-memory-violet transition-colors">
                {{ $item->title }}
            </a>
            @if ($item->subtitle !== null && $item->subtitle !== '')
                <p class="mt-1 text-xs text-slate-brand">{{ $item->subtitle }}</p>
            @endif
        </div>
        @if ($item->commitmentId !== null)
            <div class="flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('commitments.done', $item->commitmentId) }}">
                    @csrf
                    <button type="submit" class="text-xs font-medium text-neural-teal hover:text-neural-teal/80 px-2.5 py-1 rounded-lg border border-neural-teal/25 hover:bg-neural-teal/5 transition-colors">Done</button>
                </form>
                <form method="POST" action="{{ route('commitments.snooze', $item->commitmentId) }}">
                    @csrf
                    <input type="hidden" name="preset" value="tomorrow">
                    <button type="submit" class="text-xs font-medium text-slate-brand hover:text-deep-indigo px-2.5 py-1 rounded-lg border border-slate-200 hover:bg-slate-50 transition-colors">Snooze</button>
                </form>
            </div>
        @endif
    </div>
</li>
