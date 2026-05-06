<aside class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Recent updates</h2>

    <ul class="space-y-4 text-sm">
        @foreach (($overlay_deltas ?? []) as $delta)
            <li class="border-b border-memory-violet/10 pb-4 last:border-0 last:pb-0">
                <p class="font-medium text-deep-indigo">{{ $delta['label'] ?? '' }}</p>
                @if (!empty($delta['detail'] ?? ''))
                    <p class="text-slate-brand mt-1 text-[13px] leading-relaxed">{{ $delta['detail'] }}</p>
                @endif
                @if (!empty($delta['since'] ?? ''))
                    <p class="text-[11px] text-slate-brand/50 mt-1">{{ $delta['since'] }}</p>
                @endif
            </li>
        @endforeach
    </ul>
</aside>
