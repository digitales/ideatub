@extends('layouts.idea')

@section('title', ($tag ? '#'.$tag.' — ' : '').'Tag constellation — IdeaTub')

@section('content')
<div class="max-w-6xl mx-auto px-6 md:px-8 pt-16 pb-24 space-y-4">
    <div>
        <a href="{{ $tag ? route('idea.stream', ['tag' => $tag]) : route('idea.stream') }}" class="text-sm text-memory-violet hover:underline">← Back to stream</a>
        <h1 class="text-[22px] font-semibold text-deep-indigo mt-2">{{ $tag ? '#'.$tag : 'Tag constellation' }}</h1>
        <p class="text-xs text-slate-brand/70 mt-1">Thoughts tagged in your stream, connected to a tag hub.</p>
    </div>

    @if (! $tag)
        <p class="text-sm text-slate-brand/70">Add <code class="text-xs">?tag=your-tag</code> to the URL, or open from a tagged stream.</p>
    @else
        @if ($showSemanticToggle ?? false)
            <label class="inline-flex items-center gap-2 text-xs text-slate-brand">
                <input type="checkbox" id="tag-graph-semantic" class="rounded border-memory-violet/30 text-memory-violet" />
                Show similar thoughts
            </label>
        @endif

        @include('graph.partials.vis_network_canvas', [
            'dataUrl' => route('graph.tags.data', ['tag' => $tag]),
            'canvasId' => 'tag-graph-canvas',
            'emptyId' => 'tag-graph-empty',
            'emptyMessage' => 'No thoughts with this tag yet.',
            'filterCheckboxId' => ($showSemanticToggle ?? false) ? 'tag-graph-semantic' : null,
        ])
    @endif
</div>
@endsection
