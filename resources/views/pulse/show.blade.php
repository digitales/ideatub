@extends('layouts.idea')

@section('title', 'Pulse — IdeaTub')

@section('content')
<div class="max-w-3xl mx-auto px-6 pt-12 pb-24">
    <div class="mb-8">
        <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Pulse</h1>
        <p class="text-sm text-slate-brand mt-1">What needs your attention across memory, commitments, and Jira.</p>
    </div>

    @if (session('success'))
        <div class="mb-6 rounded-xl border border-neural-teal/25 bg-neural-teal/10 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    @if ($overview->isEmpty())
        <section class="rounded-xl border border-memory-violet/15 bg-white/70 backdrop-blur px-6 py-8 shadow-[0_2px_16px_rgba(109,106,247,0.06)] text-deep-indigo">
            <h2 class="text-lg font-semibold">Nothing needs attention right now</h2>
            <p class="mt-2 text-sm text-slate-brand">Pulse surfaces memory issues, open commitments, and recent Jira activity when signals appear.</p>
            <div class="mt-5 flex flex-wrap gap-2">
                @if (config('features.working_memory_ui') && \Illuminate\Support\Facades\Route::has('memory.show'))
                    <a href="{{ route('memory.show') }}" class="text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors">Working memory</a>
                @endif
                <a href="{{ route('inbox.index') }}" class="text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors">Inbox</a>
            </div>
        </section>
    @else
        <div class="space-y-8">
            @foreach ($overview->sections as $section)
                <section>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-brand">{{ $section->title }}</h2>
                    <p class="mt-1 text-sm text-slate-brand/80">{{ $section->description }}</p>
                    <ul class="mt-4 space-y-2">
                        @foreach ($section->items as $item)
                            @include('pulse.partials.item_row', ['item' => $item])
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</div>
@endsection
