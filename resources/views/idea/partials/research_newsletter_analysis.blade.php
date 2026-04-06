@if ($newsletterAnalysis)
    @if ($newsletterAnalysis['status'] === 'completed')
        <div class="mb-6 rounded-xl border border-memory-violet/20 bg-memory-violet/[0.04] p-4 md:p-5">
            <h2 class="text-[13px] md:text-[14px] font-semibold text-deep-indigo tracking-tight mb-4">Newsletter analysis</h2>

            @if ($newsletterAnalysis['summary'])
                <div class="mb-4">
                    <h3 class="text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/85 mb-2">Summary</h3>
                    <p class="text-[13px] text-slate-brand leading-relaxed">{{ $newsletterAnalysis['summary'] }}</p>
                </div>
            @endif

            @if (! empty($newsletterAnalysis['key_points']))
                <div class="mb-4">
                    <h3 class="text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/85 mb-2">Key points</h3>
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($newsletterAnalysis['key_points'] as $point)
                            <li class="text-[13px] text-slate-brand leading-relaxed">{{ $point }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($newsletterAnalysis['positives_mentioned']))
                <div class="mb-4">
                    <h3 class="text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/85 mb-2">Positives mentioned</h3>
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($newsletterAnalysis['positives_mentioned'] as $positive)
                            <li class="text-[13px] text-slate-brand leading-relaxed">{{ $positive }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($newsletterAnalysis['negatives_mentioned']))
                <div class="mb-4">
                    <h3 class="text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/85 mb-2">Negatives mentioned</h3>
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($newsletterAnalysis['negatives_mentioned'] as $negative)
                            <li class="text-[13px] text-slate-brand leading-relaxed">{{ $negative }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($newsletterAnalysis['highlights']))
                <div class="mb-4">
                    <h3 class="text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/85 mb-2">Highlights</h3>
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($newsletterAnalysis['highlights'] as $highlight)
                            <li class="text-[13px] text-slate-brand leading-relaxed">{{ $highlight }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($newsletterAnalysis['quality_notes'])
                <p class="mt-2 text-[11px] text-slate-brand/60 leading-relaxed">{{ $newsletterAnalysis['quality_notes'] }}</p>
            @endif
        </div>
    @elseif ($newsletterAnalysis['status'] === 'queued' || $newsletterAnalysis['status'] === 'processing')
        <p class="mb-4 text-[12px] text-slate-brand/70 italic">Newsletter analysis processing…</p>
    @elseif ($newsletterAnalysis['status'] === 'failed')
        <p class="mb-4 text-[12px] text-slate-brand/60 italic">Newsletter analysis could not be completed.</p>
    @endif
@endif
