@extends('layouts.idea')

@section('title', 'Ideas to revisit — Settings — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Ideas to revisit</h1>
    <p class="text-sm text-slate-brand mb-8">Control how many incomplete ideas appear on the <a href="{{ route('idea.revisit') }}" class="text-memory-violet hover:underline">Ideas to revisit</a> page and optional minimum age filter.</p>

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

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <form method="POST" action="{{ route('settings.ideas-revisit.update') }}">
            @csrf
            @method('PUT')

            <div class="space-y-4">
                <div>
                    <label for="ideas_to_revisit_limit" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Maximum number of ideas (1–50)</label>
                    <input
                        type="number"
                        name="ideas_to_revisit_limit"
                        id="ideas_to_revisit_limit"
                        min="1"
                        max="50"
                        value="{{ old('ideas_to_revisit_limit', $limit) }}"
                        required
                        class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
                    />
                    @error('ideas_to_revisit_limit')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="ideas_to_revisit_min_age_days" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Minimum age (days, optional)</label>
                    <input
                        type="number"
                        name="ideas_to_revisit_min_age_days"
                        id="ideas_to_revisit_min_age_days"
                        min="0"
                        value="{{ old('ideas_to_revisit_min_age_days', $minAgeDays) }}"
                        placeholder="Leave empty for no filter"
                        class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/40 focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
                    />
                    <p class="mt-1 text-[11px] text-slate-brand/50">Only show ideas at least this many days old.</p>
                    @error('ideas_to_revisit_min_age_days')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <button
                    type="submit"
                    class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                    style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                >
                    Save preferences
                </button>
                <a href="{{ route('idea.revisit') }}" class="text-xs font-medium text-slate-brand hover:text-memory-violet">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
