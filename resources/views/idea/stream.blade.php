@extends('layouts.idea')

@section('title', $tag ? 'Tag: ' . e($tag) . ' — IdeaTub' : 'Stream — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-6">
        @if ($tag)
            Tag: {{ e($tag) }}
        @else
            Stream
        @endif
    </h1>

    @if ($tag)
        <p class="text-center mb-4">
            <a href="{{ route('idea.stream') }}" class="text-[12px] font-medium text-memory-violet hover:underline">All thoughts</a>
        </p>
    @endif

    @if ($thoughts->isEmpty())
        <div class="rounded-xl border border-memory-violet/10 bg-white/50 px-4 py-8 text-center text-sm text-slate-brand/50">
            @if ($tag)
                No thoughts with tag ‘{{ e($tag) }}’. <a href="{{ route('idea.stream') }}" class="text-memory-violet hover:underline">All thoughts</a>
            @else
                No thoughts yet. <a href="{{ route('idea.index') }}" class="text-memory-violet hover:underline">Capture one from the home page</a>.
            @endif
        </div>
    @else
        <p class="text-[11px] text-slate-brand/40 mb-2">{{ $thoughts->total() }} thoughts</p>
        @foreach ($thoughts as $thought)
            @php
                $tags = $thought->metadata['tags'] ?? [];
                $tagColors = ['violet', 'teal', 'indigo'];
                $tagMap = [
                    'violet' => 'bg-memory-violet/10 text-memory-violet',
                    'teal'   => 'bg-neural-teal/10 text-neural-teal',
                    'indigo' => 'bg-deep-indigo/8 text-slate-brand',
                ];
            @endphp
            <div class="rounded-xl border border-memory-violet/10 bg-white/68 backdrop-blur px-4 py-3.5 mb-2 hover:bg-white/90 hover:border-memory-violet/20 transition-all">
                <p class="text-[13.5px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line">{{ e($thought->content) }}</p>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-[10.5px] text-slate-brand/40">{{ $thought->created_at->diffForHumans() }}</span>
                    @if ($thought->source)
                        <span class="text-[10.5px] text-slate-brand/40">{{ ucfirst(strtolower($thought->source)) }}</span>
                    @endif
                    @foreach ($tags as $i => $tag)
                        <a href="{{ route('idea.stream', ['tag' => \Illuminate\Support\Str::slug($tag, '_')]) }}" class="text-[10px] font-medium px-2 py-0.5 rounded-full {{ $tagMap[$tagColors[$i % 3]] }} hover:opacity-90">
                            #{{ $tag }}
                        </a>
                    @endforeach
                </div>
                @if ($thought->relationLoaded('comments') && $thought->comments->isNotEmpty())
                    <ul class="mt-3 ml-3 pl-3 border-l border-memory-violet/15 space-y-2">
                        @foreach ($thought->comments as $comment)
                            <li>
                                <p class="text-[12.5px] text-slate-brand leading-relaxed whitespace-pre-line">{{ e(Str::limit($comment->content, 200)) }}</p>
                                <p class="text-[10px] text-slate-brand/40 mt-0.5">{{ $comment->created_at->diffForHumans() }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
        @if ($thoughts->hasMorePages())
            <div class="mt-4 text-center">
                {{ $thoughts->links('pagination.idea') }}
            </div>
        @endif
    @endif
</div>
@endsection
