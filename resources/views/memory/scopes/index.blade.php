@extends('layouts.idea')

@section('title', 'All memories — IdeaTub')

@section('content')
<div class="max-w-3xl mx-auto px-6 pt-12 pb-24">
    <div class="mb-8">
        <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">All memories</h1>
        <p class="text-sm text-slate-brand mt-1">Every working memory scope currently saved for your account.</p>
    </div>

    @if (empty($sections))
        <section class="rounded-xl border border-memory-violet/15 bg-white/70 backdrop-blur px-6 py-8 shadow-[0_2px_16px_rgba(109,106,247,0.06)] text-deep-indigo">
            <h2 class="text-lg font-semibold">No saved memories yet</h2>
            <p class="mt-2 text-sm text-slate-brand">Open your global working memory to start building context from recent activity.</p>
            <a
                href="{{ route('memory.show') }}"
                class="mt-5 inline-flex items-center rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet hover:bg-memory-violet/5 transition-colors"
            >
                Open global working memory
            </a>
        </section>
    @else
        <div class="space-y-8">
            @foreach ($sections as $section)
                <section>
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-[0.16em] text-slate-brand">{{ $section['title'] }}</h2>
                    <ul class="space-y-2">
                        @foreach ($section['rows'] as $row)
                            @php
                                $badge = $row['badge'] ?? null;
                                $badgeClass = match ($badge) {
                                    'Updating' => 'border-teal-300/60 bg-teal-50 text-teal-700',
                                    'Fallback' => 'border-amber-300/70 bg-amber-50 text-amber-700',
                                    default => 'border-slate-200 bg-slate-50 text-slate-600',
                                };
                                $freshness = $row['freshness'] ?? null;
                                $refreshed = $row['refreshed'] ?? null;
                                $meta = collect([
                                    $freshness !== null ? ucfirst($freshness) : null,
                                    $refreshed !== null ? "Refreshed {$refreshed}" : null,
                                ])->filter()->implode(' · ');
                            @endphp
                            <li>
                                @if (! empty($row['href']))
                                    <a
                                        href="{{ $row['href'] }}"
                                        aria-label="{{ $row['aria_label'] }}"
                                        class="block rounded-xl border border-memory-violet/15 bg-white/70 backdrop-blur px-5 py-4 shadow-[0_2px_16px_rgba(109,106,247,0.05)] transition-colors hover:border-memory-violet/30 hover:bg-white text-deep-indigo"
                                    >
                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <div class="font-medium">{{ $row['title'] }}</div>
                                                @if ($meta !== '')
                                                    <div class="mt-1 text-sm text-slate-brand">{{ $meta }}</div>
                                                @endif
                                            </div>
                                            @if ($badge !== null)
                                                <span class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-medium {{ $badgeClass }}">{{ $badge }}</span>
                                            @endif
                                        </div>
                                    </a>
                                @else
                                    <div
                                        aria-label="{{ $row['aria_label'] }}"
                                        class="rounded-xl border border-memory-violet/15 bg-white/70 backdrop-blur px-5 py-4 shadow-[0_2px_16px_rgba(109,106,247,0.05)] text-deep-indigo"
                                    >
                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <div class="font-medium">{{ $row['title'] }}</div>
                                                @if ($meta !== '')
                                                    <div class="mt-1 text-sm text-slate-brand">{{ $meta }}</div>
                                                @endif
                                            </div>
                                            @if ($badge !== null)
                                                <span class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-medium {{ $badgeClass }}">{{ $badge }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</div>
@endsection
