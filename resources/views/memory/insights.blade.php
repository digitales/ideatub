@extends('layouts.idea')

@section('title', 'Memory insights — IdeaTub')

@section('content')
@php
    $structuredSections = is_array($payload['structured_sections'] ?? null) ? $payload['structured_sections'] : [];
    $authoringStatus = $payload['authoring_status'] ?? null;
    $renderStructuredSections = $structuredSections !== []
        && ($authoringStatus === null || $authoringStatus === 'validated');
    $references = is_array($payload['references'] ?? null) ? $payload['references'] : [];
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
<div class="max-w-3xl mx-auto px-6 pt-12 pb-24">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Memory insights</h1>
            <p class="text-sm text-slate-brand mt-1">Research-heavy signals from your recent stream-visible captures.</p>
        </div>
        @if (config('features.working_memory_ui') && \Illuminate\Support\Facades\Route::has('memory.show'))
            <div class="flex flex-wrap items-center gap-2">
                <a
                    href="{{ route('memory.scopes.index') }}"
                    class="text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors"
                >
                    All memories
                </a>
                <a
                    href="{{ route('memory.show') }}"
                    class="text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors"
                >
                    Working memory
                </a>
            </div>
        @endif
    </div>

    <article class="memory-insights-prose rounded-xl border border-memory-violet/15 bg-white/70 backdrop-blur px-6 py-8 shadow-[0_2px_16px_rgba(109,106,247,0.06)] text-deep-indigo">
        @if ($renderStructuredSections)
            @foreach ($structuredSections as $sectionTitle => $sectionItems)
                @php
                    $title = trim((string) $sectionTitle);
                    $items = is_array($sectionItems) ? $sectionItems : [$sectionItems];
                @endphp
                @continue($title === '')

                <h2>{{ $title }}</h2>
                <ul>
                    @include('memory.partials.structured_section_items', [
                        'items' => $items,
                        'isSafeCitationUrl' => $isSafeReferenceUrl,
                    ])
                </ul>
            @endforeach
        @else
            {!! \Illuminate\Support\Str::markdown($payload['summary_markdown'] ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
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
</div>
@endsection
