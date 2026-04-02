@extends('layouts.minimal')

@section('title', 'Shared document — IdeaTub')

@section('content')
<div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">{{ $documentTypeLabel ?? 'Shared document' }}</p>
    <div class="shared-research-root prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[14px] md:text-[15px]">
        {!! $root_html !!}
    </div>
    @if($sections->isNotEmpty())
        <ul class="mt-8 space-y-8 border-t border-memory-violet/10 pt-8 list-none pl-0">
            @foreach($sections as $section)
                <li>
                    <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-slate-brand prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[13px] md:text-[14px]">
                        {!! $section->content_html !!}
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
<div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-slate-brand/70">
    @if($sharedBy ?? null)
        <span>Shared by {{ e($sharedBy->name ?: $sharedBy->email) }}</span>
    @endif
    <span>{{ $root->created_at->diffForHumans() }}</span>
    <a href="{{ url('/') }}" class="font-medium text-memory-violet hover:underline">Open in IdeaTub</a>
</div>
@endsection
