@extends('layouts.idea')

@section('title', 'Similar thoughts — IdeaTub')

@section('content')
<div class="max-w-6xl mx-auto px-6 md:px-8 pt-16 pb-24 space-y-4">
    <div>
        <a href="{{ route('thoughts.show', $thought) }}" class="text-sm text-memory-violet hover:underline">← Back to thought</a>
        <h1 class="text-[22px] font-semibold text-deep-indigo mt-2">Similar thoughts</h1>
        <p class="text-xs text-slate-brand/70 mt-1 max-w-prose">{{ \Illuminate\Support\Str::limit($thought->content, 120) }}</p>
    </div>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        @include('graph.partials.vis_network_canvas', [
            'dataUrl' => route('thoughts.semantic_graph.data', $thought),
            'canvasId' => 'semantic-graph-canvas',
            'emptyId' => 'semantic-graph-empty',
            'emptyMessage' => 'No similar thoughts found (or this thought has no embedding yet).',
            'height' => 'min(72vh, 900px)',
            'minHeight' => '420px',
        ])
    </div>
</div>
@endsection
