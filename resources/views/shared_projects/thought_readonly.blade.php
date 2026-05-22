@extends('layouts.minimal')

@section('title', 'Shared thought — IdeaTub')

@section('content')
<div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80">{{ $project->title }}</p>
        <a href="{{ route('shared-projects.hub', $token) }}" class="text-sm font-medium text-memory-violet hover:underline">Back to hub</a>
    </div>
    <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline text-[14px] md:text-[15px]">
        {!! $contentHtml !!}
    </div>
</div>
<div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-slate-brand/70">
    @if($sharedBy ?? null)
        <span>Shared by {{ e($sharedBy->name ?: $sharedBy->email) }}</span>
    @endif
    <a href="{{ url('/') }}" class="font-medium text-memory-violet hover:underline">Open in IdeaTub</a>
</div>
@endsection
