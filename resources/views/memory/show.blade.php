@extends('layouts.idea')

@section('title', 'Working memory — IdeaTub')

@section('content')
@php
    $freshness = $freshness_state ?? 'stale';
    $freshnessClasses = match ($freshness) {
        'fresh' => 'bg-neural-teal/15 text-neural-teal border-neural-teal/30',
        'degraded' => 'bg-amber-50 text-amber-900 border-amber-200/80',
        default => 'bg-slate-100 text-slate-600 border-slate-200',
    };
    $overlayDeltas = $overlay_deltas ?? [];
@endphp

<div
    class="max-w-3xl mx-auto px-6 pt-12 pb-24"
    x-data="{ drawerOpen: false }"
    @keydown.escape.window="drawerOpen = false"
>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div class="min-w-0">
            <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Working memory</h1>
            <p class="text-sm text-slate-brand mt-1">Global scope — synthesized from your captures.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] {{ $freshnessClasses }}">
                {{ $freshness }}
            </span>
            @if (config('features.working_memory_insights') && \Illuminate\Support\Facades\Route::has('memory.insights'))
                <a
                    href="{{ route('memory.insights') }}"
                    class="text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors"
                >
                    Insights
                </a>
            @endif
            @if ($overlayDeltas !== [])
                <button
                    type="button"
                    @click="drawerOpen = true"
                    class="text-xs font-medium text-white px-3 py-1.5 rounded-lg transition-opacity hover:opacity-90 lg:hidden"
                    style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                >
                    Recent updates
                </button>
            @endif
        </div>
    </div>

    <details class="mb-8 rounded-xl border border-memory-violet/15 bg-white/70 backdrop-blur px-4 py-3 text-sm shadow-[0_2px_16px_rgba(109,106,247,0.06)]">
        <summary class="cursor-pointer font-medium text-deep-indigo select-none">Details</summary>
        <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-slate-brand">
            <div>
                <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Confidence</dt>
                <dd class="text-deep-indigo font-medium">{{ number_format((float) ($confidence_score ?? 0), 2) }}</dd>
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
                <dd class="text-deep-indigo font-medium">{{ $input_count ?? 0 }}</dd>
            </div>
            <div>
                <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Baseline build</dt>
                <dd class="text-deep-indigo font-medium">{{ $baseline_build_type ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Recent updates (count)</dt>
                <dd class="text-deep-indigo font-medium">{{ count($overlayDeltas) }}</dd>
            </div>
        </dl>
    </details>

    <div class="flex flex-col lg:flex-row gap-8 lg:gap-0 lg:items-start">
        <article class="flex-1 min-w-0 rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 lg:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)] prose prose-slate prose-headings:text-deep-indigo prose-a:text-memory-violet max-w-none">
            {!! \Illuminate\Support\Str::markdown($summary_markdown ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
        </article>

        @if ($overlayDeltas !== [])
            {{-- Desktop: inline panel --}}
            <aside class="hidden lg:block lg:w-[320px] lg:flex-shrink-0 lg:border-l lg:border-memory-violet/15 lg:pl-8">
                <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Recent updates</h2>
                <ul class="space-y-4 text-sm">
                    @foreach ($overlayDeltas as $delta)
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

            {{-- Mobile / small: Alpine drawer --}}
            <div
                class="lg:hidden fixed inset-0 z-40"
                x-show="drawerOpen"
                x-cloak
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
            >
                <div class="absolute inset-0 bg-deep-indigo/40" @click="drawerOpen = false" aria-hidden="true"></div>
                <div
                    class="absolute right-0 top-0 bottom-0 w-[min(100vw-3rem,380px)] bg-white shadow-2xl border-l border-memory-violet/15 overflow-y-auto p-5"
                    x-show="drawerOpen"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="translate-x-full"
                >
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-semibold text-deep-indigo">Recent updates</h2>
                        <button type="button" @click="drawerOpen = false" class="text-slate-brand hover:text-deep-indigo text-xs font-medium">Close</button>
                    </div>
                    <ul class="space-y-4 text-sm">
                        @foreach ($overlay_deltas ?? [] as $delta)
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
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
