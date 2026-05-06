@extends('layouts.idea')

@section('title', 'Working memory — Settings — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Working memory</h1>
    <p class="text-sm text-slate-brand mb-8">Optionally override how far back consolidated working memory looks when selecting inputs (deployment default: <span class="font-medium text-deep-indigo">{{ $defaultDays }}</span> days). Your effective window is <span class="font-medium text-deep-indigo">{{ $effectiveDays }}</span> days.</p>

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
        <form method="POST" action="{{ route('settings.working-memory.update') }}">
            @csrf
            @method('PUT')

            <div class="space-y-4">
                <div>
                    <label for="working_memory_consolidation_window_days" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Consolidation window (days, optional)</label>
                    <input
                        type="number"
                        name="working_memory_consolidation_window_days"
                        id="working_memory_consolidation_window_days"
                        min="1"
                        max="3650"
                        value="{{ old('working_memory_consolidation_window_days', $overrideDays) }}"
                        placeholder="Leave empty to use deployment default ({{ $defaultDays }} days)"
                        class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/40 focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
                    />
                    <p class="mt-1 text-[11px] text-slate-brand/50">Whole days, 1–3650. Clear the field to remove your override.</p>
                    @error('working_memory_consolidation_window_days')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="working_memory_forced_tags" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Forced tags (optional)</label>
                    <textarea
                        name="working_memory_forced_tags"
                        id="working_memory_forced_tags"
                        rows="5"
                        placeholder="ai, product-notes&#10;customer-research"
                        class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/40 focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
                    >{{ old('working_memory_forced_tags', $forcedTagsValue) }}</textarea>
                    <p class="mt-1 text-[11px] text-slate-brand/50">Enter tags separated by commas or new lines. Tags are normalized (trimmed, lowercased, deduplicated) before saving. Clear the field to remove forced tags.</p>
                    @error('working_memory_forced_tags')
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
                <a href="{{ route('settings.profile.index') }}" class="text-xs font-medium text-slate-brand hover:text-memory-violet">Back to profile</a>
            </div>
        </form>

        <form method="POST" action="{{ route('settings.working-memory.build-now') }}" class="mt-3">
            @csrf
            <button
                type="submit"
                class="text-xs font-medium text-memory-violet px-4 py-2 rounded-lg border border-memory-violet/25 hover:bg-memory-violet/5 transition-colors"
            >
                Build now (forced tags)
            </button>
            <p class="mt-1 text-[11px] text-slate-brand/50">Queues consolidation jobs for your currently saved forced tags.</p>
        </form>
    </div>
</div>
@endsection
