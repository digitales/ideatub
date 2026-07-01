@php
    $expanded = $expanded ?? false;
    $canvasId = $canvasId ?? 'thought-local-graph-canvas-'.$thought->id;
    $emptyId = $emptyId ?? 'thought-local-graph-empty-'.$thought->id;
@endphp

<details
    class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]"
    @if ($expanded) open @endif
>
    <summary class="cursor-pointer list-none [&::-webkit-details-marker]:hidden select-none">
        <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80">Connection graph</span>
        @unless ($expanded)
            <span class="ml-2 text-xs font-normal text-slate-brand/60">Show links and structure around this thought</span>
        @endunless
    </summary>

    <div class="mt-4 space-y-3">
        @if ($showSemanticToggle ?? false)
            <label class="inline-flex items-center gap-2 text-xs text-slate-brand">
                <input type="checkbox" id="local-graph-semantic-{{ $thought->id }}" class="rounded border-memory-violet/30 text-memory-violet" />
                Show similar thoughts
            </label>
        @endif

        @include('graph.partials.vis_network_canvas', [
            'dataUrl' => route('thoughts.graph.data', $thought),
            'canvasId' => $canvasId,
            'emptyId' => $emptyId,
            'emptyMessage' => 'No links yet — add links above to see connections.',
            'height' => $expanded ? 'min(72vh, 900px)' : 'min(40vh, 480px)',
            'minHeight' => $expanded ? '420px' : '200px',
            'filterCheckboxId' => ($showSemanticToggle ?? false) ? 'local-graph-semantic-'.$thought->id : null,
        ])

        <p class="text-xs text-slate-brand/60 flex flex-wrap gap-x-3 gap-y-1">
            <a href="{{ route('thoughts.graph', $thought) }}" class="text-memory-violet hover:underline">Open full graph</a>
            @if (config('features.memory_graph_semantic'))
                <a href="{{ route('thoughts.semantic_graph', $thought) }}" class="text-memory-violet hover:underline">Similar thoughts graph</a>
            @endif
        </p>
    </div>
</details>
