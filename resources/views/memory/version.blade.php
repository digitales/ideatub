@extends('layouts.idea')

@section('title', ($scopeTitle ?? 'Working memory').' — Historical snapshot — IdeaTub')

@section('content')
@php
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
    $snapshotAt = isset($created_at) && $created_at !== null
        ? \Illuminate\Support\Carbon::parse($created_at)->timezone(config('app.timezone'))->format('M j, Y g:i A')
        : 'unknown time';
    $confidenceDisplay = isset($confidence_score)
        ? number_format((float) $confidence_score, 2)
        : '—';
@endphp

@component('idea.partials.detail_layout_shell', ['twoColumn' => true])
    @slot('header')
        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-4">
                <a href="{{ $currentMemoryUrl }}" class="inline-flex items-center text-xs font-medium text-memory-violet hover:text-memory-violet/80">
                    ← Back to current working memory
                </a>
                @if (! empty($historyUrl))
                    <a href="{{ $historyUrl }}" class="inline-flex items-center text-xs font-medium text-memory-violet hover:text-memory-violet/80">
                        Version history
                    </a>
                @endif
            </div>
            <div class="rounded-xl border border-amber-200/80 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                Historical snapshot from {{ $snapshotAt }}
            </div>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Working memory</h1>
                    <p class="text-sm text-slate-brand mt-1">
                        {{ $scopeTitle ?? 'Scope' }} — read-only version ({{ $build_type ?? 'unknown' }}).
                    </p>
                </div>
            </div>
        </div>
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
        <aside class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Snapshot details</p>
            <dl class="grid gap-3 text-[13px] text-slate-brand">
                <div>
                    <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Created</dt>
                    <dd class="text-deep-indigo font-medium">{{ $snapshotAt }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Build type</dt>
                    <dd class="text-deep-indigo font-medium">{{ $build_type ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Authoring status</dt>
                    <dd class="text-deep-indigo font-medium">{{ $authoring_status ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Source</dt>
                    <dd class="text-deep-indigo font-medium">{{ $source_label ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Confidence</dt>
                    <dd class="text-deep-indigo font-medium">{{ $confidenceDisplay }}</dd>
                </div>
            </dl>
        </aside>
    @endslot
@endcomponent
@endsection
