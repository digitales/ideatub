@extends('layouts.idea')

@section('title', ($scopeTitle ?? 'Working memory').' — Version history — IdeaTub')

@section('content')
<div class="max-w-3xl mx-auto px-6 pt-12 pb-24">
    <div class="mb-8">
        <a
            href="{{ $currentMemoryUrl }}"
            class="inline-flex items-center text-xs font-medium text-memory-violet hover:text-memory-violet/80 mb-4"
        >
            ← Back to current working memory
        </a>
        <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Version history</h1>
        <p class="text-sm text-slate-brand mt-1">{{ $scopeTitle ?? 'Scope' }} — prior canonical snapshots.</p>
    </div>

    @if ($versions->isEmpty())
        <section class="rounded-xl border border-memory-violet/15 bg-white/70 backdrop-blur px-6 py-8 shadow-[0_2px_16px_rgba(109,106,247,0.06)] text-deep-indigo">
            <h2 class="text-lg font-semibold">No saved versions yet</h2>
            <p class="mt-2 text-sm text-slate-brand">Refresh or sync working memory to create the first snapshot.</p>
            <a
                href="{{ $currentMemoryUrl }}"
                class="mt-5 inline-flex items-center rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet hover:bg-memory-violet/5 transition-colors"
            >
                Open current working memory
            </a>
        </section>
    @else
        <ul class="space-y-2">
            @foreach ($versionRows as $row)
                @php
                    $version = $row['version'];
                    $createdAt = $row['created_at'] ?? null;
                    $createdLabel = $createdAt !== null
                        ? \Illuminate\Support\Carbon::parse($createdAt)->timezone(config('app.timezone'))->format('M j, Y g:i A')
                        : 'Unknown date';
                    $meta = collect([
                        $row['build_type'] ?? null,
                        $row['authoring_status'] ?? null,
                        $row['source_label'] ?? null,
                        isset($row['confidence_score']) ? 'Confidence '.number_format((float) $row['confidence_score'], 2) : null,
                    ])->filter()->implode(' · ');
                @endphp
                <li>
                    <a
                        href="{{ route('memory.version.show', $version) }}"
                        class="block rounded-xl border border-memory-violet/15 bg-white/70 backdrop-blur px-5 py-4 shadow-[0_2px_16px_rgba(109,106,247,0.05)] transition-colors hover:border-memory-violet/30 hover:bg-white text-deep-indigo"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="font-medium">{{ $createdLabel }}</div>
                                @if ($meta !== '')
                                    <div class="mt-1 text-sm text-slate-brand">{{ $meta }}</div>
                                @endif
                            </div>
                            <span class="shrink-0 text-xs font-medium text-memory-violet">View snapshot →</span>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($versions->hasPages())
            <nav class="mt-8" aria-label="Version history pages">
                {{ $versions->links() }}
            </nav>
        @endif
    @endif
</div>
@endsection
