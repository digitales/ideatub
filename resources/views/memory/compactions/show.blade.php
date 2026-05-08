@extends('layouts.idea')

@section('title', 'Compaction — Working memory — IdeaTub')

@section('content')
@php
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

    $references = is_array($version->references_json ?? null) ? $version->references_json : [];
    $backHref = $scopeType === 'project'
        ? '/projects/'.rawurlencode($scopeKey).'/memory'
        : ($scopeType === 'tag'
            ? '/memory/tag?tag='.rawurlencode($scopeKey)
            : '/memory');
@endphp

@component('idea.partials.detail_layout_shell', ['twoColumn' => false])
    @slot('header')
        <div class="space-y-3">
            <a href="{{ $backHref }}" class="inline-flex items-center text-xs font-medium text-memory-violet hover:text-memory-violet/80">
                ← Back to working memory
            </a>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">{{ $version->build_type }}</h1>
                    <p class="text-sm text-slate-brand mt-1">
                        Created {{ optional($version->created_at)->toIso8601String() }}
                        · Authoring status: {{ $version->authoring_status ?? 'unknown' }}
                    </p>
                </div>
            </div>
        </div>
    @endslot

    @slot('main')
        <article class="prose-memory-list-headings rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)] prose prose-slate prose-headings:text-deep-indigo prose-a:text-memory-violet max-w-none">
            <x-safe-markdown :markdown="(string) $version->summary_markdown" />
        </article>

        @if ($references !== [])
            <section class="mt-6">
                <h2 class="text-sm font-semibold uppercase tracking-[0.08em] text-slate-brand mb-3">References</h2>
                <ul class="space-y-2">
                    @foreach ($references as $reference)
                        @php
                            $url = trim((string) data_get($reference, 'url', ''));
                            $label = trim((string) data_get($reference, 'label', ''));
                            $type = trim((string) data_get($reference, 'type', ''));
                        @endphp
                        @continue($label === '')

                        <li class="text-sm text-slate-700">
                            @if ($url !== '' && $isSafeReferenceUrl($url))
                                <a href="{{ $url }}" class="text-memory-violet hover:text-memory-violet/80">{{ $label }}</a>
                            @else
                                <span>{{ $label }}</span>
                            @endif
                            @if ($type !== '')
                                <span class="ml-1 text-xs text-slate-500">[{{ $type }}]</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="mt-6">
            <h2 class="text-sm font-semibold uppercase tracking-[0.08em] text-slate-brand mb-3">Source thoughts</h2>
            @if ($version->inputs->isEmpty())
                <p class="text-sm text-slate-500">No source thoughts recorded for this compaction.</p>
            @else
                <ul class="space-y-2">
                    @foreach ($version->inputs as $input)
                        @if ($input->thought)
                            <li class="text-sm">
                                <a href="/thoughts/{{ $input->thought->id }}" class="text-memory-violet hover:text-memory-violet/80">
                                    {{ \Illuminate\Support\Str::limit((string) $input->thought->content, 120) }}
                                </a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endif
        </section>
    @endslot
@endcomponent
@endsection
