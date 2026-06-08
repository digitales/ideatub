@extends('layouts.idea')

@section('title', ($isTagScope ?? false)
    ? ('Tag: '.e($scopeTitle ?? 'Tag').' — Working memory — IdeaTub')
    : (($isProjectScope ?? false)
        ? (($scopeTitle ?? 'Project').' — Working memory — IdeaTub')
        : 'Working memory — IdeaTub'))

@section('content')
@php
    $isProject = $isProjectScope ?? false;
    $isTag = $isTagScope ?? false;
    $tagRefreshScopeKey = $tagRefreshScopeKey ?? '';
    $refreshAction = $isTag
        ? \Illuminate\Support\Facades\URL::signedRoute('working-memory.refresh.tag', ['tag' => $tagRefreshScopeKey])
        : ($isProject && ! empty($project)
            ? route('working-memory.refresh.project', $project)
            : route('working-memory.refresh.global'));
    $freshness = $freshness_state ?? 'stale';
    $freshnessClasses = match ($freshness) {
        'fresh' => 'bg-neural-teal/15 text-neural-teal border-neural-teal/30',
        'degraded' => 'bg-amber-50 text-amber-900 border-amber-200/80',
        default => 'bg-slate-100 text-slate-600 border-slate-200',
    };
    $overlayDeltas = $overlay_deltas ?? [];
    $references = is_array($references ?? null) ? $references : [];
    $externalProtected = false;
    if (($baseline_build_type ?? '') === 'external'
        && ($authoring_status ?? '') === 'external'
        && ! empty($canonical_created_at)) {
        $protectDays = max(0, (int) config('working_memory.external_protect_days', 14));
        if ($protectDays > 0) {
            $externalProtected = \Illuminate\Support\Carbon::parse($canonical_created_at)
                ->gte(now()->subDays($protectDays));
        }
    }
    $aiAuthoringEnabled = config('features.working_memory_ai_authored')
        && config('working_memory.authoring_enabled');
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
        @include('memory.partials.show_header', [
            'isProject' => $isProject,
            'isTag' => $isTag,
            'project' => $project ?? null,
            'scopeTitle' => $scopeTitle ?? null,
            'tagSlugQuery' => $tagSlugQuery ?? null,
            'tagRefreshScopeKey' => $tagRefreshScopeKey,
            'freshness' => $freshness,
            'freshnessClasses' => $freshnessClasses,
            'baseline_build_type' => $baseline_build_type ?? null,
            'externalProtected' => $externalProtected,
            'refreshAction' => $refreshAction,
            'aiAuthoringEnabled' => $aiAuthoringEnabled,
        ])
    @endslot

    @slot('main')
        <article class="prose-memory-list-headings rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)] prose prose-slate prose-headings:text-deep-indigo prose-a:text-memory-violet max-w-none">
            @include('memory.partials.structured_sections_content', [
                'structured_sections' => $structured_sections ?? [],
                'authoring_status' => $authoring_status ?? null,
                'summary_markdown' => $summary_markdown ?? '',
                'isSafeReferenceUrl' => $isSafeReferenceUrl,
            ])
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
            'source_label' => $source_label ?? null,
            'canonical_created_at' => $canonical_created_at ?? null,
            'authoring_status' => $authoring_status ?? null,
            'overlay_deltas' => $overlayDeltas,
        ])

        @if ($overlayDeltas !== [])
            @include('memory.partials.recent_updates_card', ['overlay_deltas' => $overlayDeltas])
        @endif
    @endslot
@endcomponent
@endsection
