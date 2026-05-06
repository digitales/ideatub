@extends('layouts.idea')

@section('title', 'Memory insights — IdeaTub')

@section('content')
<div class="max-w-3xl mx-auto px-6 pt-12 pb-24">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Memory insights</h1>
            <p class="text-sm text-slate-brand mt-1">Research-heavy signals from your recent stream-visible captures.</p>
        </div>
        @if (config('features.working_memory_ui') && \Illuminate\Support\Facades\Route::has('memory.show'))
            <a
                href="{{ route('memory.show') }}"
                class="text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors"
            >
                Working memory
            </a>
        @endif
    </div>

    <article class="memory-insights-prose rounded-xl border border-memory-violet/15 bg-white/70 backdrop-blur px-6 py-8 shadow-[0_2px_16px_rgba(109,106,247,0.06)] text-deep-indigo">
        {!! \Illuminate\Support\Str::markdown($payload['summary_markdown'] ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
    </article>
</div>
@endsection
