@extends('layouts.idea')

@section('title', $project->title.' — Graph — IdeaTub')

@section('content')
@php
    use App\Enums\ThoughtLinkType;
    $baseDataUrl = route('projects.graph.data', $project);
@endphp
<div class="max-w-6xl mx-auto px-6 md:px-8 pt-16 pb-24 space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('projects.show', $project) }}" class="text-sm text-memory-violet hover:underline">← Back to project</a>
            <h1 class="text-[22px] font-semibold text-deep-indigo mt-2">{{ $project->title }} — graph</h1>
            <p class="text-xs text-slate-brand/70 mt-1">Members only. Double-click a node to open the thought.</p>
        </div>
    </div>

    <form id="project-graph-filters" class="flex flex-wrap items-center gap-4 rounded-2xl border border-memory-violet/15 bg-white/70 px-4 py-3 text-xs text-slate-brand">
        <fieldset class="flex flex-wrap gap-3 border-0 p-0 m-0">
            <legend class="sr-only">Link types</legend>
            @foreach (ThoughtLinkType::cases() as $case)
                <label class="inline-flex items-center gap-1.5">
                    <input type="checkbox" name="link_types[]" value="{{ $case->value }}" class="rounded border-memory-violet/30 text-memory-violet" checked />
                    {{ $case->label() }}
                </label>
            @endforeach
        </fieldset>
        <label class="inline-flex items-center gap-1.5">
            <input type="checkbox" name="include_neighbors" value="1" class="rounded border-memory-violet/30 text-memory-violet" />
            Neighbors
        </label>
        <label class="inline-flex items-center gap-1.5">
            <input type="checkbox" name="include_parent_child" value="1" class="rounded border-memory-violet/30 text-memory-violet" />
            Sections
        </label>
        @if ($showSemanticToggle ?? false)
            <label class="inline-flex items-center gap-1.5">
                <input type="checkbox" name="include_semantic" value="1" class="rounded border-memory-violet/30 text-memory-violet" />
                Similar
            </label>
        @endif
        <button type="button" id="project-graph-reset" class="text-memory-violet hover:underline">Reset</button>
    </form>

    <div id="project-graph-canvas" class="w-full rounded-2xl border border-memory-violet/20 bg-white/80 shadow-[0_4px_24px_rgba(109,106,247,0.08)]" style="min-height: 420px; height: min(72vh, 900px);"></div>
    <p id="project-graph-empty" class="hidden text-sm text-slate-brand/70">Add thoughts to this project to see the graph.</p>
</div>

@push('scripts')
<script src="https://unpkg.com/vis-network@9.1.9/standalone/umd/vis-network.min.js"></script>
<script>
(function () {
    const container = document.getElementById('project-graph-canvas');
    const emptyMsg = document.getElementById('project-graph-empty');
    const form = document.getElementById('project-graph-filters');
    const baseUrl = @json($baseDataUrl);
    if (!container || !form || typeof vis === 'undefined') return;

    let network = null;

    function buildUrl() {
        const params = new URLSearchParams();
        form.querySelectorAll('input[name="link_types[]"]:checked').forEach(function (el) {
            params.append('link_types[]', el.value);
        });
        ['include_neighbors', 'include_parent_child', 'include_semantic'].forEach(function (name) {
            const el = form.querySelector('input[name="' + name + '"]');
            if (el && el.checked) params.set(name, '1');
        });
        const qs = params.toString();
        return qs ? baseUrl + '?' + qs : baseUrl;
    }

    function nodeStyle(n) {
        const group = n.group || 'member';
        const colors = {
            focal: { background: '#ede9fe', border: '#6D6AF7' },
            neighbor: { background: '#f8fafc', border: '#cbd5e1' },
            chunk: { background: '#f1f5f9', border: '#94a3b8' },
            member: { background: '#f5f3ff', border: '#c4b5fd' },
        };
        const c = colors[group] || colors.member;
        return { id: n.id, label: n.label || n.id, shape: 'box', color: { background: c.background, border: c.border, highlight: { background: '#ede9fe', border: '#6D6AF7' } } };
    }

    function edgeStyle(e, index) {
        return {
            id: e.id || ('e' + index),
            from: e.from,
            to: e.to,
            label: e.label || '',
            dashes: e.dashed === true || e.edge_type === 'semantic',
            arrows: (e.directed === false || e.edge_type === 'semantic') ? '' : 'to',
        };
    }

    function fitGraph() {
        if (network) network.fit({ padding: 48, animation: false });
    }

    function loadGraph() {
        fetch(buildUrl(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                const nodes = new vis.DataSet((data.nodes || []).map(nodeStyle));
                const edges = new vis.DataSet((data.edges || []).map(edgeStyle));
                if (nodes.length === 0) {
                    emptyMsg.classList.remove('hidden');
                    container.style.minHeight = '120px';
                    if (network) { network.destroy(); network = null; }
                    return;
                }
                emptyMsg.classList.add('hidden');
                if (network) {
                    network.setData({ nodes: nodes, edges: edges });
                } else {
                    network = new vis.Network(container, { nodes: nodes, edges: edges }, {
                        nodes: { margin: 10, font: { size: 13, face: 'Inter, system-ui, sans-serif' } },
                        edges: { font: { size: 11, align: 'middle' }, smooth: { type: 'continuous' } },
                        physics: { stabilization: { iterations: 120 } },
                        interaction: { hover: true, tooltipDelay: 200 },
                    });
                    network.once('stabilizationIterationsDone', fitGraph);
                    network.on('doubleClick', function (params) {
                        if (params.nodes.length) window.location.href = @json(url('/thoughts')) + '/' + params.nodes[0];
                    });
                }
                fitGraph();
            })
            .catch(function () {
                emptyMsg.textContent = 'Could not load graph data.';
                emptyMsg.classList.remove('hidden');
            });
    }

    form.addEventListener('change', loadGraph);
    document.getElementById('project-graph-reset').addEventListener('click', function () {
        form.querySelectorAll('input[type="checkbox"]').forEach(function (el) {
            if (el.name === 'link_types[]') el.checked = true;
            else el.checked = false;
        });
        loadGraph();
    });

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(fitGraph, 150);
    });

    loadGraph();
})();
</script>
@endpush
@endsection
