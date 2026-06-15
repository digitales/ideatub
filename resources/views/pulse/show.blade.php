@extends('layouts.idea')

@section('title', 'Pulse — IdeaTub')

@section('content')
@php
    $navBtnClass = 'inline-flex items-center rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet transition-colors hover:bg-memory-violet/5 hover:text-memory-violet/80';
    $signalCount = $overview->totalCount();
@endphp

<div class="mx-auto w-full max-w-4xl px-6 pb-24 pt-12 md:px-8">
    <header class="mb-8">
        <p class="mb-2 text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90">Attention</p>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-[28px] font-semibold tracking-tight text-deep-indigo text-balance">Pulse</h1>
                <p class="mt-1.5 max-w-[48ch] text-pretty text-sm text-slate-brand">
                    Memory health, open commitments, and recent Jira activity in one place.
                </p>
            </div>
            <nav class="flex shrink-0 flex-wrap items-center gap-2" aria-label="Pulse navigation">
                <a href="{{ route('inbox.index') }}" class="{{ $navBtnClass }}">Inbox</a>
                @if (config('features.working_memory_ui') && \Illuminate\Support\Facades\Route::has('memory.scopes.index'))
                    <a href="{{ route('memory.scopes.index') }}" class="{{ $navBtnClass }}">All memories</a>
                @endif
            </nav>
        </div>
    </header>

    @if (session('success'))
        <div
            class="mb-6 rounded-2xl border border-neural-teal/25 bg-neural-teal/10 px-4 py-3 text-sm text-neural-teal ring-1 ring-neural-teal/15"
            role="status"
        >
            {{ session('success') }}
        </div>
    @endif

    @if ($overview->isEmpty())
        <div class="ideatub-surface-muted px-6 py-10 text-center">
            <h2 class="text-lg font-semibold text-deep-indigo">Nothing needs attention right now</h2>
            <p class="mx-auto mt-2 max-w-[42ch] text-pretty text-sm text-slate-brand">
                Pulse surfaces memory issues, open commitments, and recent Jira activity when signals appear.
            </p>
            <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                @if (config('features.working_memory_ui') && \Illuminate\Support\Facades\Route::has('memory.show'))
                    <a href="{{ route('memory.show') }}" class="{{ $navBtnClass }}">Working memory</a>
                @endif
                <a href="{{ route('inbox.index') }}" class="{{ $navBtnClass }}">Open inbox</a>
            </div>
        </div>
    @else
        <p class="mb-8 rounded-2xl bg-white/70 px-4 py-3 text-sm text-slate-brand ring-1 ring-deep-indigo/[0.06]">
            <span class="font-medium text-deep-indigo tabular-nums">{{ $signalCount }}</span>
            {{ $signalCount === 1 ? 'signal needs' : 'signals need' }}
            attention across
            <span class="font-medium text-deep-indigo tabular-nums">{{ count($overview->sections) }}</span>
            {{ count($overview->sections) === 1 ? 'section' : 'sections' }}
        </p>

        <div class="flex flex-col gap-6">
            @foreach ($overview->sections as $section)
                <section aria-labelledby="pulse-section-{{ $section->key }}">
                    <div class="ideatub-surface overflow-hidden">
                        <div class="border-b border-deep-indigo/[0.06] px-5 py-4">
                            <h2 id="pulse-section-{{ $section->key }}" class="text-sm font-semibold text-deep-indigo">
                                {{ $section->title }}
                            </h2>
                            <p class="mt-1 max-w-[60ch] text-pretty text-sm text-slate-brand">
                                {{ $section->description }}
                            </p>
                        </div>

                        <ul role="list" class="divide-y divide-deep-indigo/[0.06]">
                            @foreach ($section->items as $item)
                                @include('pulse.partials.item_row', ['item' => $item])
                            @endforeach
                        </ul>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
@endsection
