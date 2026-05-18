@php
    $confidenceDisplay = isset($confidence_score) && $confidence_score !== ''
        ? number_format((float) $confidence_score, 2)
        : '—';
    $inputCountDisplay = isset($input_count) && $input_count !== ''
        ? (string) $input_count
        : '—';
    $canonicalVersionDisplay = isset($canonical_created_at) && $canonical_created_at !== null && $canonical_created_at !== ''
        ? \Illuminate\Support\Carbon::parse($canonical_created_at)
            ->timezone(config('app.timezone'))
            ->format('M j, Y g:i A')
        : '—';
@endphp

<aside class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Details</p>

    <dl class="grid gap-3 sm:grid-cols-2 text-[13px] text-slate-brand">
        <div>
            <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Confidence</dt>
            <dd class="text-deep-indigo font-medium">{{ $confidenceDisplay }}</dd>
        </div>
        <div>
            <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Last refreshed</dt>
            <dd class="text-deep-indigo font-medium">{{ $last_refreshed_at ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Consolidation window (days)</dt>
            <dd class="text-deep-indigo font-medium">{{ $effective_consolidation_window_days ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Input count</dt>
            <dd class="text-deep-indigo font-medium">{{ $inputCountDisplay }}</dd>
        </div>
        <div>
            <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Baseline build</dt>
            <dd class="text-deep-indigo font-medium">{{ $baseline_build_type ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Source</dt>
            <dd class="text-deep-indigo font-medium">{{ $source_label ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Canonical version</dt>
            <dd class="text-deep-indigo font-medium">{{ $canonicalVersionDisplay }}</dd>
        </div>
        <div>
            <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Authoring status</dt>
            <dd class="text-deep-indigo font-medium">{{ $authoring_status ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Recent updates (count)</dt>
            <dd class="text-deep-indigo font-medium">{{ count($overlay_deltas ?? []) }}</dd>
        </div>
    </dl>
</aside>
