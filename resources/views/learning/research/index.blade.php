@extends('layouts.idea')

@section('title', 'Research — '.$learningProject->title.' — Learn — IdeaTub')

@section('content')
<div class="max-w-[920px] mx-auto px-6 pt-16 pb-24">
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="text-xs font-semibold tracking-[0.14em] uppercase text-memory-violet/70 mb-2">Research</div>
            <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">{{ $learningProject->title }}</h1>
        </div>

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('learn.projects.show', $learningProject) }}" class="text-sm font-medium text-memory-violet hover:underline">
                Back to project
            </a>
        </div>
    </div>

    @if ($documents->isEmpty())
        <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 text-sm text-slate-brand">
            No research documents synced yet.
        </div>
    @else
        <div class="space-y-3">
            @foreach ($documents as $doc)
                <a
                    href="{{ route('learn.research.show', [$learningProject, $doc->slug]) }}"
                    class="block rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur px-5 py-4 hover:border-memory-violet/40 transition-colors"
                >
                    <div class="text-sm font-semibold text-deep-indigo">{{ $doc->title }}</div>
                    @if ($doc->summary)
                        <div class="mt-2 text-sm text-slate-brand">{{ $doc->summary }}</div>
                    @endif
                    <div class="mt-2 text-xs text-slate-brand/70">
                        slug: <span class="font-mono">{{ $doc->slug }}</span>
                        @if ($doc->category)
                            <span class="ml-3">category: <span class="font-mono">{{ $doc->category }}</span></span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
