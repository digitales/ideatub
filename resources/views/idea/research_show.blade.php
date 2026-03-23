@extends('layouts.idea')

@section('title', Str::limit($root->content, 50) . ' — IdeaTub')

@section('content')
<div class="max-w-4xl mx-auto px-6 md:px-8 pt-16 pb-24">
    <p class="mb-4">
        <a href="{{ request()->query('from') === 'ideas' ? route('idea.ideas') : route('idea.stream') }}" class="text-[12px] font-medium text-memory-violet hover:underline">
            {{ request()->query('from') === 'ideas' ? '← Back to Ideas' : '← Back to Stream' }}
        </a>
    </p>
    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Research</p>
        @if (! empty($relatedEmail))
            <div class="mb-6 rounded-xl border border-memory-violet/25 bg-memory-violet/5 p-4 md:p-5">
                <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Related email</p>
                <p class="text-[14px] md:text-[15px] font-semibold text-deep-indigo">{{ $relatedEmail['subject'] }}</p>
                <p class="text-[13px] text-slate-brand mt-1">{{ $relatedEmail['sender'] }}</p>
                <p class="mt-3">
                    <a href="{{ $relatedEmail['url'] }}" class="text-[13px] font-medium text-memory-violet hover:underline">View email</a>
                </p>
            </div>
        @endif
        <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[14px] md:text-[15px]">
            {!! $root_html !!}
        </div>
        <p class="text-[11px] text-slate-brand/50 mt-4">{{ $root->created_at->diffForHumans() }}</p>
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
</div>
@endsection
