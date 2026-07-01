@extends('layouts.idea')

@section('title', 'Connection graph — IdeaTub')

@section('content')
<div class="max-w-6xl mx-auto px-6 md:px-8 pt-16 pb-24 space-y-4">
    <div>
        <a href="{{ route('thoughts.show', $thought) }}" class="text-sm text-memory-violet hover:underline">← Back to thought</a>
        <h1 class="text-[22px] font-semibold text-deep-indigo mt-2">Connection graph</h1>
        <p class="text-xs text-slate-brand/70 mt-1 max-w-prose">{{ \Illuminate\Support\Str::limit($thought->content, 120) }}</p>
        <p class="text-xs text-slate-brand/70 mt-1">Double-click a node to open the thought.</p>
    </div>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] space-y-3">
        @if ($showSemanticToggle ?? false)
            <label class="inline-flex items-center gap-2 text-xs text-slate-brand">
                <input type="checkbox" id="local-graph-semantic-full" class="rounded border-memory-violet/30 text-memory-violet" />
                Show similar thoughts
            </label>
        @endif

        @include('graph.partials.vis_network_canvas', [
            'dataUrl' => route('thoughts.graph.data', $thought),
            'canvasId' => 'thought-local-graph-canvas-full',
            'emptyId' => 'thought-local-graph-empty-full',
            'emptyMessage' => 'No links yet — add links on the thought page to see connections.',
            'height' => 'min(72vh, 900px)',
            'minHeight' => '420px',
            'filterCheckboxId' => ($showSemanticToggle ?? false) ? 'local-graph-semantic-full' : null,
        ])
    </div>
</div>
@endsection
