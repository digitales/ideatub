@extends('layouts.minimal')

@section('title', $project->title.' — Shared project — IdeaTub')

@section('content')
<div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-2">Shared project</p>
    <h1 class="text-xl font-semibold text-deep-indigo mb-2">{{ $project->title }}</h1>
    @if ($descriptionHtml)
        <div class="prose prose-sm prose-slate max-w-none text-slate-brand mb-6">{!! $descriptionHtml !!}</div>
    @endif

    <div class="flex flex-wrap gap-3 mb-6">
        <a href="{{ route('shared-projects.read', $token) }}" class="text-sm font-medium text-white px-4 py-2 rounded-lg hover:opacity-90" style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">Read all</a>
    </div>

    @if (! empty($contextThought))
        @include('projects.partials.context-thought', [
            'project' => $project,
            'contextThought' => $contextThought,
            'editable' => false,
            'contextLabel' => 'Project context',
            'contextThoughtUrl' => route('shared-projects.thought', ['token' => $token, 'thoughtId' => $contextThought->id]),
        ])
    @endif

    <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Items</h2>
    @if (($memberThoughts ?? $project->thoughts)->isEmpty() && empty($contextThought))
        <p class="text-sm text-slate-brand/70">This project has no items yet.</p>
    @else
        <ul class="space-y-2 list-none pl-0">
            @foreach ($memberThoughts ?? $project->thoughts as $thought)
                <li>
                    <a href="{{ route('shared-projects.thought', ['token' => $token, 'thoughtId' => $thought->id]) }}" class="block rounded-xl border border-memory-violet/10 bg-white/60 px-4 py-3 text-sm text-deep-indigo hover:border-memory-violet/30 hover:bg-memory-violet/5">
                        {{ \Illuminate\Support\Str::limit($thought->content, 160) }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
<div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-slate-brand/70">
    @if($sharedBy ?? null)
        <span>Shared by {{ e($sharedBy->name ?: $sharedBy->email) }}</span>
    @endif
    <a href="{{ url('/') }}" class="font-medium text-memory-violet hover:underline">Open in IdeaTub</a>
</div>
@endsection
