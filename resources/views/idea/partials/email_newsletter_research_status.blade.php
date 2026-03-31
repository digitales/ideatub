@php
    $researchStatus = $newsletterResearchStatus['status'] ?? null;
    $researchThoughtId = $newsletterResearchStatus['research_thought_id'] ?? null;
    $skipReason = $newsletterResearchStatus['skip_reason'] ?? '';
    $showResearchLink = (bool) ($newsletterResearchStatus['show_research_link'] ?? false);
    $showSkipInfo = (bool) ($newsletterResearchStatus['show_skip_info'] ?? false);
@endphp
@if (is_string($researchStatus) && $researchStatus !== '')
    @php
        $labels = [
            'research_queued' => 'Research queued',
            'research_completed' => 'Research ready',
            'research_partial' => 'Partial research',
            'research_skipped' => 'Research skipped',
            'research_failed' => 'Research failed',
        ];
        $label = $labels[$researchStatus] ?? ucfirst(str_replace('_', ' ', $researchStatus));
        $skipReasonPopoverId = 'email-research-skip-reason-'.($thought->id ?? 'status');
    @endphp
    <span
        class="inline-flex items-center rounded-md border border-memory-violet/20 bg-memory-violet/5 px-1.5 py-0.5 text-[10px] font-medium text-memory-violet/80"
        data-email-research-status="{{ $researchStatus }}"
    >{{ $label }}</span>
    @if ($showResearchLink)
        <a href="{{ route('idea.research.show', $researchThoughtId) }}" class="text-[10.5px] font-medium text-memory-violet hover:underline">View research</a>
    @endif
    @if ($showSkipInfo)
        <span class="text-[10px] text-slate-brand/60">Skipped: {{ $skipReason }}</span>
        <span
            class="relative inline-flex max-w-full align-middle"
            x-data="{
                fromHover: false,
                fromFocus: false,
                fromClick: false,
                get reveal() { return this.fromHover || this.fromFocus || this.fromClick },
                close() {
                    this.fromHover = false
                    this.fromFocus = false
                    this.fromClick = false
                },
            }"
            @mouseenter="fromHover = true"
            @mouseleave="fromHover = false"
            @keydown.escape.window="close()"
            @click.outside="close()"
        >
            <button
                type="button"
                class="text-[10px] font-medium text-memory-violet/80 underline decoration-memory-violet/30 underline-offset-2 hover:text-memory-violet focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/40 rounded-sm"
                aria-controls="{{ $skipReasonPopoverId }}"
                x-bind:aria-expanded="reveal ? 'true' : 'false'"
                @focus="fromFocus = true"
                @blur="fromFocus = false"
                @click="fromClick = !fromClick"
            >Why research was skipped</button>
            <span
                aria-hidden="true"
                data-email-research-skip-hover-bridge
                class="absolute left-0 top-full h-1 w-full"
            ></span>
            <div
                id="{{ $skipReasonPopoverId }}"
                data-email-research-skip-reason
                x-show="reveal"
                x-cloak
                class="absolute left-0 top-full z-50 mt-1 max-w-[min(20rem,calc(100vw-2rem))] rounded-lg border border-memory-violet/15 bg-white px-2.5 py-2 text-left text-[11px] leading-snug text-slate-brand shadow-lg"
            >{{ $skipReason }}</div>
        </span>
    @endif
@endif
