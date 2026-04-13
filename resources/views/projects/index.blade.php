@extends('layouts.idea')

@section('title', 'Projects — IdeaTub')

@section('content')
<div class="max-w-[640px] mx-auto px-6 pt-16 pb-24">
    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    <div class="flex items-center justify-between gap-4 mb-8">
        <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Projects</h1>
        <a href="{{ route('projects.create') }}" class="text-sm font-medium text-memory-violet hover:underline">New project</a>
    </div>

    @if ($projects->isEmpty())
        <p class="text-sm text-slate-brand/70">No projects yet. Create one to group ideas, notes, and plans.</p>
    @else
        <ul class="space-y-3">
            @foreach ($projects as $project)
                <li>
                    <a href="{{ route('projects.show', $project) }}" class="block rounded-xl border border-memory-violet/15 bg-white/70 hover:bg-white px-4 py-3 transition-colors">
                        <span class="font-medium text-deep-indigo">{{ $project->title }}</span>
                        @if ($project->description)
                            <p class="text-xs text-slate-brand/60 mt-1 line-clamp-2">{{ \Illuminate\Support\Str::limit(strip_tags($project->description), 120) }}</p>
                        @endif
                        <p class="text-[11px] text-slate-brand/45 mt-2">{{ $project->top_level_ideas_count === 1 ? '1 idea' : $project->top_level_ideas_count.' ideas' }} · Updated {{ $project->updated_at->diffForHumans() }}</p>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
