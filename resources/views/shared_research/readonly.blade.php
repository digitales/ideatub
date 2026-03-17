@extends('layouts.minimal')

@section('title', Str::limit($root->content, 50))

@section('content')
<div class="rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-4">
    <div class="whitespace-pre-line text-[13.5px] text-deep-indigo leading-relaxed">{{ e($root->content) }}</div>
    @if($sections->isNotEmpty())
        <ul class="mt-4 space-y-3 border-t border-memory-violet/10 pt-4">
            @foreach($sections as $section)
                <li>
                    <div class="whitespace-pre-line text-[12.5px] text-slate-brand leading-relaxed">{{ e($section->content) }}</div>
                    <p class="text-[10px] text-slate-brand/40 mt-1">{{ $section->created_at->diffForHumans() }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
