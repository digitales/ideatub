@extends('layouts.minimal')

@section('title', $project->title.' — Read all — IdeaTub')

@section('content')
<div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)] space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1">Shared project</p>
            <h1 class="text-xl font-semibold text-deep-indigo">{{ $project->title }}</h1>
        </div>
        <a href="{{ route('shared-projects.hub', $token) }}" class="text-sm font-medium text-memory-violet hover:underline">Hub</a>
    </div>

    @forelse ($blocks as $block)
        <article class="border-t border-memory-violet/10 pt-8 first:border-t-0 first:pt-0">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-brand/50 mb-3">Thought</p>
            <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-p:text-deep-indigo prose-p:leading-relaxed text-[14px] md:text-[15px]">
                {!! $block->content_html !!}
            </div>
        </article>
    @empty
        <p class="text-sm text-slate-brand/70">No content in this project.</p>
    @endforelse
</div>
<div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-slate-brand/70">
    @if($sharedBy ?? null)
        <span>Shared by {{ e($sharedBy->name ?: $sharedBy->email) }}</span>
    @endif
    <a href="{{ url('/') }}" class="font-medium text-memory-violet hover:underline">Open in IdeaTub</a>
</div>
@endsection
