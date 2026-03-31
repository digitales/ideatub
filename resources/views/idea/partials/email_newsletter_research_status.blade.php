@if ($newsletterResearchStatus)
    <span
        class="inline-flex items-center rounded-md border border-memory-violet/20 bg-memory-violet/5 px-1.5 py-0.5 text-[10px] font-medium text-memory-violet/80"
        data-email-research-status="{{ $newsletterResearchStatus->status() }}"
    >{{ $newsletterResearchStatus->label() }}</span>
    @if ($newsletterResearchStatus->showsResearchLink())
        <a href="{{ route('idea.research.show', $newsletterResearchStatus->researchThoughtId()) }}" class="text-[10.5px] font-medium text-memory-violet hover:underline">View research</a>
    @endif
    @if ($newsletterResearchStatus->showsSkipInfo())
        <span class="text-[10px] text-slate-brand/60">Skipped: {{ $newsletterResearchStatus->skipReason() }}</span>
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
                aria-controls="{{ $newsletterResearchStatus->skipReasonPopoverId() }}"
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
                id="{{ $newsletterResearchStatus->skipReasonPopoverId() }}"
                data-email-research-skip-reason
                x-show="reveal"
                x-cloak
                class="absolute left-0 top-full z-50 mt-1 max-w-[min(20rem,calc(100vw-2rem))] rounded-lg border border-memory-violet/15 bg-white px-2.5 py-2 text-left text-[11px] leading-snug text-slate-brand shadow-lg"
            >{{ $newsletterResearchStatus->skipReason() }}</div>
        </span>
    @endif
@endif
