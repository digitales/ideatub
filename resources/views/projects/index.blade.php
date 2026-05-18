@extends('layouts.idea')

@section('title', 'Projects — IdeaTub')

@section('content')
<div class="max-w-7xl mx-auto px-6 pt-10 pb-20 w-full">
    @if (session('success'))
        <div class="mb-6 rounded-2xl bg-neural-teal/10 px-4 py-3 text-sm text-neural-teal ring-1 ring-neural-teal/20">
            {{ session('success') }}
        </div>
    @endif

    <header class="mb-8">
        <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90 mb-2">Workspace</p>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-3xl font-semibold tracking-tight text-deep-indigo">Projects</h1>
                <p class="mt-1.5 text-sm text-slate-brand max-w-[48ch]">Group ideas, notes, and plans by client or initiative.</p>
            </div>
            <a href="{{ route('projects.create') }}" class="ideatub-btn-primary shrink-0 gap-2">
                New project
            </a>
        </div>
    </header>

    @if ($projects->isEmpty())
        <div class="ideatub-surface-muted px-6 py-12 text-center">
            <p class="text-sm text-slate-brand/70 max-w-sm mx-auto">No projects yet. Create one to group ideas, notes, and plans.</p>
            <a href="{{ route('projects.create') }}" class="ideatub-btn-primary mt-5 gap-2">
                New project
            </a>
        </div>
    @else
        <ul class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3" role="list">
            @foreach ($projects as $project)
                <li class="min-w-0">
                    <a href="{{ route('projects.show', $project) }}" class="ideatub-surface group flex h-full flex-col p-5 transition hover:ring-memory-violet/25 dark:hover:ring-violet-400/30">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-memory-violet/10 text-memory-violet" aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/>
                                </svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <span class="font-semibold text-deep-indigo group-hover:text-memory-violet transition-colors line-clamp-1">{{ $project->title }}</span>
                                @if ($project->description)
                                    <p class="mt-1.5 text-sm text-slate-brand/65 line-clamp-2 leading-relaxed">{{ \Illuminate\Support\Str::limit(strip_tags($project->description), 140) }}</p>
                                @endif
                            </div>
                        </div>
                        <p class="mt-4 pt-3 border-t border-deep-indigo/[0.05] text-xs text-slate-brand/50">
                            <span class="font-medium text-slate-brand/70">{{ $project->top_level_ideas_count === 1 ? '1 idea' : $project->top_level_ideas_count.' ideas' }}</span>
                            <span class="mx-1.5 text-slate-brand/30">·</span>
                            Updated {{ $project->updated_at->diffForHumans() }}
                        </p>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
