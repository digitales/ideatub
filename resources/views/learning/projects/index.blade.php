@extends('layouts.idea')

@section('title', 'Learn — IdeaTub')

@section('content')
<div class="max-w-[720px] mx-auto px-6 pt-16 pb-24">
    <div class="mb-8">
        <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Learn</h1>
        <p class="mt-3 text-sm text-slate-brand">
            Repo-learning workspaces synced from markdown on disk.
        </p>
    </div>

    @if ($projects->isEmpty())
        <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 text-sm text-slate-brand">
            No learning projects yet.
        </div>
    @else
        <div class="space-y-3">
            @foreach ($projects as $project)
                <a
                    href="{{ route('learn.projects.show', $project) }}"
                    class="block rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur px-5 py-4 hover:border-memory-violet/40 transition-colors"
                >
                    <div class="text-sm font-semibold text-deep-indigo">{{ $project->title }}</div>
                    <div class="mt-1 text-xs text-slate-brand/80">slug: <span class="font-mono">{{ $project->slug }}</span></div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
