@if ($editorialLinkSummaries['show'] ?? false)
    <div class="mt-8 rounded-xl border border-memory-violet/20 bg-memory-violet/[0.04] p-4 md:p-5">
        <h2 class="text-[13px] md:text-[14px] font-semibold text-deep-indigo tracking-tight">Editorial link summaries</h2>
        @if (($editorialLinkSummaries['pending_count'] ?? 0) > 0)
            <p class="mt-2 text-[11px] md:text-[12px] text-slate-brand/80">
                {{ $editorialLinkSummaries['pending_count'] }} editorial link{{ $editorialLinkSummaries['pending_count'] === 1 ? '' : 's' }} still pending.
            </p>
        @endif
        @if (($editorialLinkSummaries['failed_count'] ?? 0) > 0)
            <p class="mt-1 text-[11px] md:text-[12px] text-amber-800/90">
                {{ $editorialLinkSummaries['failed_count'] }} editorial link{{ $editorialLinkSummaries['failed_count'] === 1 ? '' : 's' }} failed to summarize.
            </p>
        @endif
        <div class="mt-4 space-y-6">
            @foreach ($editorialLinkSummaries['sections'] ?? [] as $section)
                <div>
                    <h3 class="text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/85 mb-3">{{ $section['label'] }}</h3>
                    <ul class="space-y-4 list-none pl-0 m-0">
                        @foreach ($section['items'] ?? [] as $item)
                            <li class="border-b border-memory-violet/10 pb-4 last:border-0 last:pb-0">
                                <p class="text-[13px] md:text-[14px] font-semibold text-deep-indigo">{{ $item['title'] }}</p>
                                <p class="mt-1">
                                    <a href="{{ $item['url'] }}" class="text-[12px] font-medium text-memory-violet hover:underline break-all" target="_blank" rel="noopener noreferrer">{{ $item['url'] }}</a>
                                </p>
                                @if (! empty($item['summary_text']))
                                    <p class="mt-2 text-[12px] md:text-[13px] text-slate-brand leading-relaxed whitespace-pre-wrap">{{ $item['summary_text'] }}</p>
                                @endif
                                @if (! empty($item['relation_label']))
                                    <p class="mt-2 text-[11px] text-slate-brand/80">
                                        <span class="font-medium text-slate-brand">Relation:</span>
                                        {{ $item['relation_label'] }}
                                    </p>
                                @endif
                                @if (! empty($item['why_it_matters']))
                                    <p class="mt-2 text-[12px] text-deep-indigo/90 leading-relaxed whitespace-pre-wrap"><span class="font-medium text-deep-indigo">Why it matters:</span> {{ $item['why_it_matters'] }}</p>
                                @endif
                                @if (! empty($item['quality_notes']))
                                    <p class="mt-2 text-[11px] text-slate-brand/60 leading-relaxed whitespace-pre-wrap">{{ $item['quality_notes'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>
@endif
