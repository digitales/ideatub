@extends('layouts.idea')

@section('title', $project->title.' — Graph — IdeaTub')

@section('content')
<div class="max-w-6xl mx-auto px-6 md:px-8 pt-16 pb-24 space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('projects.show', $project) }}" class="text-sm text-memory-violet hover:underline">← Back to project</a>
            <h1 class="text-[22px] font-semibold text-deep-indigo mt-2">{{ $project->title }} — graph</h1>
            <p class="text-xs text-slate-brand/70 mt-1">Members only. Double-click a node to open the thought.</p>
        </div>
    </div>

    <div id="project-graph-canvas" class="w-full rounded-2xl border border-memory-violet/20 bg-white/80 shadow-[0_4px_24px_rgba(109,106,247,0.08)]" style="min-height: 420px; height: min(72vh, 900px);"></div>
    <p id="project-graph-empty" class="hidden text-sm text-slate-brand/70">Add thoughts to this project to see the graph.</p>
</div>

@push('scripts')
<script src="https://unpkg.com/vis-network/standalone/umd/vis-network.min.js"></script>
<script>
(function () {
    const container = document.getElementById('project-graph-canvas');
    const emptyMsg = document.getElementById('project-graph-empty');
    if (!container || typeof vis === 'undefined') return;

    fetch(@json(route('projects.graph.data', $project)), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            const nodes = new vis.DataSet((data.nodes || []).map(function (n) {
                return { id: n.id, label: n.label || n.id };
            }));
            const edges = new vis.DataSet((data.edges || []).map(function (e, i) {
                return { id: 'e' + i, from: e.from, to: e.to, label: e.label || '' };
            }));

            if (nodes.length === 0) {
                emptyMsg.classList.remove('hidden');
                container.style.minHeight = '120px';
                return;
            }

            const network = new vis.Network(container, { nodes: nodes, edges: edges }, {
                nodes: { shape: 'box', margin: 10, font: { size: 13, face: 'Inter, system-ui, sans-serif' }, color: { background: '#f5f3ff', border: '#c4b5fd', highlight: { background: '#ede9fe', border: '#6D6AF7' } } },
                edges: { arrows: 'to', font: { size: 11, align: 'middle' }, smooth: { type: 'continuous' } },
                physics: { stabilization: { iterations: 120 } },
                interaction: { hover: true, tooltipDelay: 200 }
            });

            function fitGraph() {
                network.fit({ padding: 48, animation: false });
            }

            network.once('stabilizationIterationsDone', fitGraph);

            var resizeTimer;
            window.addEventListener('resize', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(fitGraph, 150);
            });

            var thoughtBase = @json(url('/thoughts'));
            network.on('doubleClick', function (params) {
                if (params.nodes.length) {
                    window.location.href = thoughtBase + '/' + params.nodes[0];
                }
            });
        })
        .catch(function () {
            emptyMsg.textContent = 'Could not load graph data.';
            emptyMsg.classList.remove('hidden');
        });
})();
</script>
@endpush
@endsection
