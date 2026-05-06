@extends('layouts.idea')

@section('title', ($isProjectScope ?? false)
    ? (($scopeTitle ?? 'Project').' — Working memory — IdeaTub')
    : 'Working memory — IdeaTub')

@section('content')
@php
    $isProject = $isProjectScope ?? false;
    $refreshAction = $isProject
        ? route('working-memory.refresh.project', $project)
        : route('working-memory.refresh.global');
    $freshness = $freshness_state ?? 'stale';
    $freshnessClasses = match ($freshness) {
        'fresh' => 'bg-neural-teal/15 text-neural-teal border-neural-teal/30',
        'degraded' => 'bg-amber-50 text-amber-900 border-amber-200/80',
        default => 'bg-slate-100 text-slate-600 border-slate-200',
    };
    $overlayDeltas = $overlay_deltas ?? [];
    $structuredSections = is_array($structured_sections ?? null) ? $structured_sections : [];
    $authoringStatus = $authoring_status ?? null;
    $renderStructuredSections = $structuredSections !== []
        && ($authoringStatus === null || $authoringStatus === 'validated');
    $references = is_array($references ?? null) ? $references : [];
    $isSafeReferenceUrl = static function (string $url): bool {
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '') {
            return in_array($scheme, ['http', 'https'], true)
                && filter_var($url, FILTER_VALIDATE_URL) !== false;
        }

        return str_starts_with($url, '/')
            && ! str_starts_with($url, '//');
    };
@endphp

@component('idea.partials.detail_layout_shell', ['twoColumn' => true])
    @slot('header')
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Working memory</h1>
                <p class="text-sm text-slate-brand mt-1">
                    @if ($isProject)
                        {{ $scopeTitle ?? ($project->title ?? 'Project') }} — synthesized from captures linked to this project.
                    @else
                        Global scope — synthesized from your captures.
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] {{ $freshnessClasses }}">
                    {{ $freshness }}
                </span>
                <form
                    method="POST"
                    action="{{ $refreshAction }}"
                    onsubmit="const button=this.querySelector('button[type=submit]'); if(!button||button.disabled){return false;} button.disabled=true; button.setAttribute('aria-busy','true'); return true;"
                >
                    @csrf
                    <button
                        type="submit"
                        class="text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors"
                    >
                        Refresh working memory
                    </button>
                </form>
                @if (! $isProject && config('features.working_memory_insights'))
                    <a
                        href="{{ route('memory.insights') }}"
                        class="text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors"
                    >
                        Insights
                    </a>
                @endif
            </div>
        </div>
    @endslot

    @slot('main')
        <article class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)] prose prose-slate prose-headings:text-deep-indigo prose-a:text-memory-violet max-w-none">
            @if ($renderStructuredSections)
                @foreach ($structuredSections as $sectionTitle => $sectionItems)
                    @php
                        $title = trim((string) $sectionTitle);
                        $items = is_array($sectionItems) ? $sectionItems : [$sectionItems];
                    @endphp
                    @continue($title === '')

                    <h2>{{ $title }}</h2>
                    <ul>
                        @foreach ($items as $item)
                            @php
                                $itemText = trim((string) $item);
                            @endphp
                            @continue($itemText === '')
                            <li>{{ $itemText }}</li>
                        @endforeach
                    </ul>
                @endforeach
            @else
                {!! \Illuminate\Support\Str::markdown($summary_markdown ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
            @endif
        </article>

        @if ($references !== [])
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($references as $reference)
                    @php
                        $url = trim((string) data_get($reference, 'url', ''));
                        $label = trim((string) data_get($reference, 'label', ''));
                    @endphp
                    @continue($url === '' || $label === '' || ! $isSafeReferenceUrl($url))

                    <a
                        href="{{ $url }}"
                        class="inline-flex items-center rounded-full border border-memory-violet/20 px-2.5 py-1 text-xs text-memory-violet hover:bg-memory-violet/5 transition-colors"
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        @endif
    @endslot

    @slot('sidebar')
        @include('memory.partials.details_card', [
            'confidence_score' => $confidence_score ?? null,
            'last_refreshed_at' => $last_refreshed_at ?? null,
            'effective_consolidation_window_days' => $effective_consolidation_window_days ?? null,
            'input_count' => $input_count ?? null,
            'baseline_build_type' => $baseline_build_type ?? null,
            'overlay_deltas' => $overlayDeltas,
        ])

        @if ($overlayDeltas !== [])
            @include('memory.partials.recent_updates_card', ['overlay_deltas' => $overlayDeltas])
        @endif
    @endslot
@endcomponent
@endsection
