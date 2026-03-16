@extends('layouts.idea')

@section('title', 'Ideas — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">

    {{-- Flash messages --}}
    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-600">
            {{ session('error') }}
        </div>
    @endif

    <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-6">Ideas</h1>

    {{-- Add idea form --}}
    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-4 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Add idea</h2>
        <form method="POST" action="{{ route('ideas.store') }}">
            @csrf
            <textarea
                name="content"
                id="content"
                rows="3"
                required
                placeholder="What's the idea?"
                class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/40 focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50 resize-none"
            >{{ old('content') }}</textarea>
            @error('content')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <label class="text-[11px] text-slate-brand/70">
                    Logged date (optional):
                    <input
                        type="date"
                        name="logged_date"
                        value="{{ old('logged_date', now()->toDateString()) }}"
                        class="ml-1 rounded-md border border-slate-200 px-2 py-1 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30"
                    />
                </label>
                <button
                    type="submit"
                    class="text-xs font-medium text-white px-4 py-1.5 rounded-lg transition-opacity hover:opacity-90"
                    style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                >
                    Save idea
                </button>
            </div>
        </form>
        <p class="text-[11px] text-slate-brand/50 mt-3 mb-1">Or add an idea and run research:</p>
        <form method="POST" action="{{ route('ideas.research-new') }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <label class="flex-1 min-w-[200px]">
                <span class="sr-only">Idea to research</span>
                <input
                    type="text"
                    name="content"
                    value="{{ old('content') }}"
                    placeholder="Research this idea: …"
                    class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/40 focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
                />
            </label>
            <button
                type="submit"
                class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #2A8C8C, #6D6AF7);"
            >
                Research this idea
            </button>
        </form>
    </div>

    {{-- Ideas list --}}
    @include('idea.partials.ideas_list', ['ideas' => $ideas, 'researchByIdea' => $researchByIdea])
</div>
@endsection
