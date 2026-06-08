{{-- UI exploration: working memory inline on project page. Remove picker scaffolding after design approval. --}}
@php
    $sampleSections = [
        'Current Focus' => [
            'Ship project working memory inline on the project detail page.',
            'Keep `/projects/{id}/memory` as deep-link / history destination.',
        ],
        'Active Priorities' => [
            'Reuse structured section partials from the memory show view.',
            'Collapsible Details panel below the eight sections.',
        ],
        'Next Actions' => [
            'Load assembler payload in ProjectController when feature flag is on.',
            'Remove redundant sidebar working-memory stub after inline ships.',
        ],
    ];
    $sampleFreshness = 'fresh';
    $freshnessClasses = 'bg-neural-teal/15 text-neural-teal border-neural-teal/30';
@endphp

<div data-uidotsh-pick="Working memory layout" class="contents">
    {{-- Option 1: Frosted article (memory page parity) --}}
    <div data-uidotsh-option="Frosted article" class="contents">
        <section class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90 mb-1">Working memory</p>
                    <p class="text-sm text-slate-brand/70 max-w-[48ch]">Synthesized from captures linked to this project.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] {{ $freshnessClasses }}">
                        {{ $sampleFreshness }}
                    </span>
                    <span class="inline-flex items-center rounded-full border border-memory-violet/30 bg-memory-violet/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-memory-violet">
                        Synced from agent
                    </span>
                    <button type="button" class="text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors">
                        Refresh
                    </button>
                    <a href="#" class="text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors">
                        History
                    </a>
                </div>
            </div>

            <article class="prose-memory-list-headings rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)] prose prose-slate prose-headings:text-deep-indigo prose-a:text-memory-violet max-w-none">
                @foreach ($sampleSections as $title => $items)
                    <h2>{{ $title }}</h2>
                    <ul>
                        @foreach ($items as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @endforeach
            </article>

            <details class="group rounded-2xl border border-memory-violet/15 bg-white/60 open:bg-white/80 transition-colors">
                <summary class="cursor-pointer list-none px-5 py-4 flex items-center justify-between gap-3 text-sm font-medium text-deep-indigo [&::-webkit-details-marker]:hidden">
                    <span>Details</span>
                    <span class="text-xs font-normal text-slate-brand/60 group-open:hidden">Confidence, source, inputs…</span>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4 text-slate-brand/50 transition group-open:rotate-180">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.25a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08Z" clip-rule="evenodd" />
                    </svg>
                </summary>
                <div class="px-5 pb-5 pt-0 border-t border-deep-indigo/[0.06]">
                    @include('memory.partials.details_card', [
                        'confidence_score' => 90,
                        'last_refreshed_at' => '2 hours ago',
                        'effective_consolidation_window_days' => 90,
                        'input_count' => 142,
                        'baseline_build_type' => 'external',
                        'source_label' => 'cursor-sync',
                        'canonical_created_at' => now()->subHours(2),
                        'authoring_status' => 'external',
                        'overlay_deltas' => [],
                    ])
                </div>
            </details>
        </section>
    </div>

    {{-- Option 2: Unified surface --}}
    <div data-uidotsh-option="Unified surface" class="contents" hidden>
        <section class="ideatub-surface px-5 py-5 sm:px-6 sm:py-6">
            <div class="flex flex-wrap items-start justify-between gap-4 pb-5 border-b border-deep-indigo/[0.06]">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-deep-indigo">Working memory</h2>
                    <p class="mt-1 text-sm text-slate-brand/70 max-w-[48ch]">Live synthesis for agents and humans working on this project.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] {{ $freshnessClasses }}">{{ $sampleFreshness }}</span>
                    <button type="button" class="ideatub-btn-primary px-3 py-1.5 text-xs">Refresh</button>
                    <a href="#" class="text-xs font-medium text-memory-violet hover:underline">History</a>
                </div>
            </div>

            <div class="mt-5 prose-memory-list-headings prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo">
                @foreach ($sampleSections as $title => $items)
                    <h3 class="!text-base !font-semibold !mt-6 first:!mt-0">{{ $title }}</h3>
                    <ul class="!mt-2">
                        @foreach ($items as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @endforeach
            </div>

            <details class="mt-6 rounded-xl border border-deep-indigo/[0.08] bg-deep-indigo/[0.02] open:bg-deep-indigo/[0.03]">
                <summary class="cursor-pointer list-none px-4 py-3 text-sm font-medium text-deep-indigo flex items-center justify-between [&::-webkit-details-marker]:hidden">
                    Details
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4 text-slate-brand/50">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.25a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08Z" clip-rule="evenodd" />
                    </svg>
                </summary>
                <dl class="grid gap-3 px-4 pb-4 sm:grid-cols-2 text-[13px] text-slate-brand border-t border-deep-indigo/[0.06] pt-3">
                    <div><dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Confidence</dt><dd class="font-medium text-deep-indigo">90.00</dd></div>
                    <div><dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Last refreshed</dt><dd class="font-medium text-deep-indigo">2 hours ago</dd></div>
                    <div><dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Source</dt><dd class="font-medium text-deep-indigo">cursor-sync</dd></div>
                    <div><dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Inputs</dt><dd class="font-medium text-deep-indigo">142</dd></div>
                </dl>
            </details>
        </section>
    </div>

    {{-- Option 3: Accent panel --}}
    <div data-uidotsh-option="Accent panel" class="contents" hidden>
        <section class="rounded-2xl border border-memory-violet/20 border-l-[4px] border-l-memory-violet bg-gradient-to-br from-memory-violet/[0.06] via-white/95 to-white p-5 sm:p-6 shadow-[0_4px_24px_rgba(109,106,247,0.06)]">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-memory-violet/15 text-memory-violet" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5">
                            <path d="M10 2a.75.75 0 0 1 .673.418l1.882 3.815 4.21.612a.75.75 0 0 1 .416 1.279l-3.046 2.97.719 4.192a.75.75 0 0 1-1.088.791L10 14.347l-3.766 1.98a.75.75 0 0 1-1.088-.79l.72-4.194L2.818 8.124a.75.75 0 0 1 .416-1.28l4.21-.611L9.327 2.42A.75.75 0 0 1 10 2Z" />
                        </svg>
                    </span>
                    <div>
                        <h2 class="text-lg font-semibold text-deep-indigo">Working memory</h2>
                        <p class="text-xs text-slate-brand/65 mt-0.5"><span class="font-medium text-neural-teal capitalize">{{ $sampleFreshness }}</span> · synced from agent · refreshed 2h ago</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-medium text-memory-violet ring-1 ring-memory-violet/25 hover:bg-memory-violet/5">Refresh</button>
                    <a href="#" class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-brand ring-1 ring-deep-indigo/[0.08] hover:bg-white/80">History</a>
                </div>
            </div>

            <div class="rounded-xl bg-white/85 ring-1 ring-deep-indigo/[0.06] p-5 prose-memory-list-headings prose prose-sm max-w-none prose-headings:text-deep-indigo">
                @foreach ($sampleSections as $title => $items)
                    <h3 class="!text-sm !font-semibold !tracking-[0.06em] !uppercase !text-memory-violet/90 !mt-5 first:!mt-0">{{ $title }}</h3>
                    <ul class="!mt-2 !text-slate-brand">
                        @foreach ($items as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @endforeach
            </div>

            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-memory-violet hover:text-memory-violet/80 list-none flex items-center gap-1.5 [&::-webkit-details-marker]:hidden">
                    Show details
                </summary>
                <dl class="mt-3 grid gap-2 sm:grid-cols-4 text-xs text-slate-brand">
                    <div class="rounded-lg bg-white/70 px-3 py-2 ring-1 ring-deep-indigo/[0.05]"><dt class="text-[10px] uppercase tracking-wider text-slate-brand/55">Confidence</dt><dd class="font-semibold text-deep-indigo mt-0.5">90</dd></div>
                    <div class="rounded-lg bg-white/70 px-3 py-2 ring-1 ring-deep-indigo/[0.05]"><dt class="text-[10px] uppercase tracking-wider text-slate-brand/55">Inputs</dt><dd class="font-semibold text-deep-indigo mt-0.5">142</dd></div>
                    <div class="rounded-lg bg-white/70 px-3 py-2 ring-1 ring-deep-indigo/[0.05]"><dt class="text-[10px] uppercase tracking-wider text-slate-brand/55">Source</dt><dd class="font-semibold text-deep-indigo mt-0.5">cursor-sync</dd></div>
                    <div class="rounded-lg bg-white/70 px-3 py-2 ring-1 ring-deep-indigo/[0.05]"><dt class="text-[10px] uppercase tracking-wider text-slate-brand/55">Build</dt><dd class="font-semibold text-deep-indigo mt-0.5">external</dd></div>
                </dl>
            </details>
        </section>
    </div>

    {{-- Option 4: Stacked wells --}}
    <div data-uidotsh-option="Stacked wells" class="contents" hidden>
        <section>
            <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-deep-indigo">Working memory</h2>
                    <p class="mt-1 text-sm text-slate-brand/70">Eight sections · last refreshed 2 hours ago</p>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <span class="rounded-full bg-neural-teal/15 px-2.5 py-1 font-semibold uppercase tracking-wide text-neural-teal">{{ $sampleFreshness }}</span>
                    <button type="button" class="font-medium text-memory-violet hover:underline">Refresh</button>
                    <span class="text-slate-brand/30">·</span>
                    <a href="#" class="font-medium text-memory-violet hover:underline">History</a>
                </div>
            </div>

            <div class="space-y-3">
                @foreach ($sampleSections as $title => $items)
                    <div class="rounded-xl bg-deep-indigo/[0.03] px-4 py-4 sm:px-5 ring-1 ring-deep-indigo/[0.05]">
                        <h3 class="text-sm font-semibold text-deep-indigo">{{ $title }}</h3>
                        <ul class="mt-2 space-y-1.5 text-sm text-slate-brand list-disc pl-5">
                            @foreach ($items as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

            <details class="mt-4 rounded-xl ring-1 ring-deep-indigo/[0.08] bg-white/70">
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-deep-indigo list-none [&::-webkit-details-marker]:hidden">Details &amp; metadata</summary>
                <div class="px-4 pb-4 text-sm text-slate-brand border-t border-deep-indigo/[0.06] pt-3">
                    Confidence 90 · 142 inputs · external build · source cursor-sync
                </div>
            </details>
        </section>
    </div>
</div>
