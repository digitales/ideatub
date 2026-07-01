@props([
    'dataUrl',
    'canvasId' => 'graph-canvas',
    'emptyId' => 'graph-empty',
    'emptyMessage' => 'No connections to display.',
    'height' => 'min(72vh, 900px)',
    'minHeight' => '420px',
    'thoughtUrlBase' => null,
    'filterCheckboxId' => null,
    'filterParam' => 'include_semantic',
])

<div
    id="{{ $canvasId }}"
    class="w-full rounded-2xl border border-memory-violet/20 bg-white/80 shadow-[0_4px_24px_rgba(109,106,247,0.08)]"
    style="min-height: {{ $minHeight }}; height: {{ $height }};"
></div>
<p id="{{ $emptyId }}" class="hidden text-sm text-slate-brand/70">{{ $emptyMessage }}</p>

@once
    @push('scripts')
        <script src="https://unpkg.com/vis-network@9.1.9/standalone/umd/vis-network.min.js"></script>
    @endpush
@endonce

@push('scripts')
<script>
(function () {
    const container = document.getElementById(@json($canvasId));
    const emptyMsg = document.getElementById(@json($emptyId));
    const dataUrl = @json($dataUrl);
    const thoughtBase = @json($thoughtUrlBase ?? url('/thoughts'));
    const filterCheckboxId = @json($filterCheckboxId);
    const filterParam = @json($filterParam);

    if (!container || typeof vis === 'undefined') return;

    function nodeStyle(n) {
        const group = n.group || 'member';
        const colors = {
            focal: { background: '#ede9fe', border: '#6D6AF7' },
            neighbor: { background: '#f8fafc', border: '#cbd5e1' },
            chunk: { background: '#f1f5f9', border: '#94a3b8' },
            hub: { background: '#ecfdf5', border: '#2dd4bf' },
            member: { background: '#f5f3ff', border: '#c4b5fd' },
        };
        const c = colors[group] || colors.member;

        return {
            id: n.id,
            label: n.label || n.id,
            shape: group === 'hub' ? 'ellipse' : 'box',
            color: { background: c.background, border: c.border, highlight: { background: '#ede9fe', border: '#6D6AF7' } },
        };
    }

    function edgeStyle(e, index) {
        const dashed = e.dashed === true || e.edge_type === 'semantic';
        const arrows = e.directed === false || e.edge_type === 'semantic' || e.edge_type === 'shared_tag'
            ? ''
            : 'to';

        return {
            id: e.id || ('e' + index),
            from: e.from,
            to: e.to,
            label: e.label || '',
            dashes: dashed,
            arrows: arrows,
        };
    }

    function resolveDataUrl() {
        if (!filterCheckboxId) return dataUrl;
        const el = document.getElementById(filterCheckboxId);
        if (!el || !el.checked) return dataUrl;
        const sep = dataUrl.indexOf('?') >= 0 ? '&' : '?';
        return dataUrl + sep + encodeURIComponent(filterParam) + '=1';
    }

    let network = null;

    function loadGraph() {
        fetch(resolveDataUrl(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
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

                function fitGraph() {
                    if (network) network.fit({ padding: 48, animation: false });
                }

                if (network) {
                    network.setData({ nodes: nodes, edges: edges });
                    fitGraph();
                    return;
                }

                network = new vis.Network(container, { nodes: nodes, edges: edges }, {
                    nodes: { margin: 10, font: { size: 13, face: 'Inter, system-ui, sans-serif' } },
                    edges: { font: { size: 11, align: 'middle' }, smooth: { type: 'continuous' } },
                    physics: { stabilization: { iterations: 120 } },
                    interaction: { hover: true, tooltipDelay: 200 },
                });

                network.once('stabilizationIterationsDone', fitGraph);

                var resizeTimer;
                window.addEventListener('resize', function () {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(fitGraph, 150);
                });

                network.on('doubleClick', function (params) {
                    if (!params.nodes.length) return;
                    const nodeId = params.nodes[0];
                    if (String(nodeId).indexOf('tag:') === 0) return;
                    window.location.href = thoughtBase + '/' + nodeId;
                });
            })
            .catch(function () {
                emptyMsg.textContent = 'Could not load graph data.';
                emptyMsg.classList.remove('hidden');
            });
    }

    if (filterCheckboxId) {
        const filterEl = document.getElementById(filterCheckboxId);
        if (filterEl) filterEl.addEventListener('change', loadGraph);
    }

    loadGraph();
})();
</script>
@endpush
