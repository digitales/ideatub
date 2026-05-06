@extends('layouts.idea')

@section('title', $document->title.' — Research — '.$learningProject->title.' — Learn — IdeaTub')

@section('content')
<div class="max-w-[920px] mx-auto px-6 pt-16 pb-24">
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="text-xs font-semibold tracking-[0.14em] uppercase text-memory-violet/70 mb-2">Research</div>
            <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">{{ $document->title }}</h1>
            <div class="mt-2 text-xs text-slate-brand/70">
                slug: <span class="font-mono">{{ $document->slug }}</span>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('learn.research.index', $learningProject) }}" class="text-sm font-medium text-memory-violet hover:underline">
                All research
            </a>
            <a href="{{ route('learn.projects.show', $learningProject) }}" class="text-sm font-medium text-memory-violet hover:underline">
                Project
            </a>
        </div>
    </div>

    <article class="prose prose-sm max-w-none text-slate-brand">
        {!! $bodyHtml !!}
    </article>
</div>
@endsection
