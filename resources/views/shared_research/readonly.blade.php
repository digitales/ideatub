@extends('layouts.minimal')

@section('title', Str::limit($root->content, 50))

@section('content')
<div class="rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-4">
    <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100 prose-pre:text-deep-indigo prose-code:text-deep-indigo prose-code:bg-slate-100 prose-code:px-1 prose-code:rounded prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline text-[13.5px]">
        {!! $root_html !!}
    </div>
    @if($sections->isNotEmpty())
        <ul class="mt-4 space-y-4 border-t border-memory-violet/10 pt-4 list-none pl-0">
            @foreach($sections as $section)
                <li>
                    <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-p:text-slate-brand prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100 prose-pre:text-deep-indigo prose-code:text-deep-indigo prose-code:bg-slate-100 prose-code:px-1 prose-code:rounded prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline text-[12.5px]">
                        {!! $section->content_html !!}
                    </div>
                    <p class="text-[10px] text-slate-brand/40 mt-1">{{ $section->created_at->diffForHumans() }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
