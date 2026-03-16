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
    <div class="flex items-center justify-between mt-9 mb-3.5">
        <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-slate-brand/50">Your ideas</span>
        <span class="text-[11px] text-slate-brand/30">{{ $ideas->total() }} total</span>
    </div>

    @if ($ideas->isEmpty())
        <div class="rounded-xl border border-memory-violet/10 bg-white/50 px-4 py-8 text-center text-sm text-slate-brand/50">
            No ideas yet. Add one above.
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($ideas as $thought)
                @php
                    $researchList = $researchByIdea->get($thought->id, collect());
                @endphp
                <li class="rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3 flex items-start gap-3">
                    <form method="POST" action="{{ route('ideas.toggle-completed', $thought) }}" class="flex-shrink-0 mt-0.5">
                        @csrf
                        @method('PATCH')
                        <label class="cursor-pointer">
                            <input
                                type="checkbox"
                                class="rounded border-slate-300 text-neural-teal focus:ring-memory-violet/30"
                                {{ $thought->isIdeaCompleted() ? 'checked' : '' }}
                                onchange="this.form.submit()"
                            />
                            <span class="sr-only">Mark as {{ $thought->isIdeaCompleted() ? 'incomplete' : 'complete' }}</span>
                        </label>
                    </form>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-deep-indigo {{ $thought->isIdeaCompleted() ? 'line-through text-slate-brand/70' : '' }}">
                            {{ Str::limit($thought->content, 200) }}
                        </p>
                        <p class="text-[11px] text-slate-brand/50 mt-1">{{ $thought->getLoggedDate() }}</p>
                        {{-- Research block --}}
                        <div class="mt-2 pt-2 border-t border-memory-violet/10">
                            @if ($thought->isResearchPending())
                                <p class="text-xs text-slate-brand/70 flex items-center gap-1.5">
                                    <span class="inline-block size-3.5 rounded-full border-2 border-neural-teal/50 border-t-neural-teal animate-spin" aria-hidden="true"></span>
                                    Researching…
                                </p>
                                @if ($researchList->isNotEmpty())
                                    <p class="text-[11px] font-semibold text-slate-brand/60 uppercase tracking-wide mb-1 mt-2">Research</p>
                                    @foreach ($researchList as $research)
                                        <div class="text-sm text-slate-brand/80 mb-2">
                                            <p>{{ Str::limit($research->content, 120) }}</p>
                                            <details class="mt-1">
                                                <summary class="text-xs text-neural-teal cursor-pointer hover:underline">View full</summary>
                                                <div class="mt-1 p-2 rounded-lg bg-slate-50/80 text-sm text-deep-indigo whitespace-pre-wrap">{{ $research->content }}</div>
                                            </details>
                                        </div>
                                    @endforeach
                                @endif
                            @elseif ($researchList->isEmpty())
                                <form method="POST" action="{{ route('ideas.research', $thought) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs font-medium text-neural-teal hover:underline">
                                        Research this idea
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('ideas.research', $thought) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs font-medium text-neural-teal hover:underline">Regenerate</button>
                                </form>
                                <p class="text-[11px] font-semibold text-slate-brand/60 uppercase tracking-wide mb-1 mt-1">Research</p>
                                @foreach ($researchList as $research)
                                    <div class="text-sm text-slate-brand/80 mb-2">
                                        <p>{{ Str::limit($research->content, 120) }}</p>
                                        <details class="mt-1">
                                            <summary class="text-xs text-neural-teal cursor-pointer hover:underline">View full</summary>
                                            <div class="mt-1 p-2 rounded-lg bg-slate-50/80 text-sm text-deep-indigo whitespace-pre-wrap">{{ $research->content }}</div>
                                        </details>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        @if ($ideas->hasMorePages())
            <div class="mt-4 flex justify-center">
                {{ $ideas->links('pagination.idea') }}
            </div>
        @endif
    @endif
</div>
@endsection
