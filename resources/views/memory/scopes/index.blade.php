@extends('layouts.idea')

@section('title', 'All memories — IdeaTub')

@section('content')
@php
    $navBtnClass = 'inline-flex items-center rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet transition-colors hover:bg-memory-violet/5 hover:text-memory-violet/80';

    $scopeCount = collect($sections ?? [])
        ->sum(function (array $section): int {
            if (($section['key'] ?? '') === 'clients') {
                return collect($section['groups'] ?? [])->sum(fn (array $group): int => count($group['rows'] ?? []));
            }

            return count($section['rows'] ?? []);
        });
@endphp

<div class="mx-auto w-full max-w-4xl px-6 pb-24 pt-12 md:px-8">
    <header class="mb-8">
        <p class="mb-2 text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90">Working memory</p>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-[28px] font-semibold tracking-tight text-deep-indigo">All memories</h1>
                <p class="mt-1.5 max-w-[48ch] text-sm text-slate-brand">
                    Every scope with saved working memory — global, projects, tags, and insights.
                </p>
            </div>
            <nav class="flex shrink-0 flex-wrap items-center gap-2" aria-label="Working memory navigation">
                <a href="{{ route('memory.show') }}" class="{{ $navBtnClass }}">
                    Global memory
                </a>
                @if (config('features.working_memory_insights'))
                    <a href="{{ route('memory.insights') }}" class="{{ $navBtnClass }}">
                        Insights
                    </a>
                @endif
            </nav>
        </div>
    </header>

    @if (empty($sections))
        <div class="ideatub-surface-muted px-6 py-10 text-center">
            <h2 class="text-lg font-semibold text-deep-indigo">No saved memories yet</h2>
            <p class="mx-auto mt-2 max-w-[40ch] text-sm text-slate-brand">
                Open your global working memory to start building context from recent activity.
            </p>
            <a href="{{ route('memory.show') }}" class="{{ $navBtnClass }} mt-5">
                Open global working memory
            </a>
        </div>
    @else
        <p class="mb-8 rounded-2xl bg-white/70 px-4 py-3 text-sm text-slate-brand ring-1 ring-deep-indigo/[0.06]">
            <span class="font-medium text-deep-indigo tabular-nums">{{ $scopeCount }}</span>
            {{ $scopeCount === 1 ? 'scope' : 'scopes' }}
            across
            <span class="font-medium text-deep-indigo tabular-nums">{{ count($sections) }}</span>
            {{ count($sections) === 1 ? 'section' : 'sections' }}
        </p>

        <div class="flex flex-col gap-6">
            @foreach ($sections as $section)
                <section aria-labelledby="memory-section-{{ $section['key'] }}">
                    <div class="ideatub-surface overflow-hidden">
                        <div class="border-b border-deep-indigo/[0.06] px-5 py-3.5">
                            <h2 id="memory-section-{{ $section['key'] }}" class="text-sm font-semibold text-deep-indigo">
                                {{ $section['title'] }}
                            </h2>
                        </div>

                        @if (($section['key'] ?? '') === 'clients')
                            <div class="divide-y divide-deep-indigo/[0.06]">
                                @foreach ($section['groups'] ?? [] as $group)
                                    <div class="px-5 py-4">
                                        <h3 class="mb-3 text-sm font-medium text-slate-brand">
                                            {{ $group['client_title'] }}
                                        </h3>
                                        <div class="overflow-x-auto">
                                            @include('memory.partials.scopes_table', ['rows' => $group['rows'] ?? []])
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="overflow-x-auto px-5 py-4">
                                @include('memory.partials.scopes_table', ['rows' => $section['rows'] ?? []])
                            </div>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
@endsection
