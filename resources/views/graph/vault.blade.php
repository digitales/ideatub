@extends('layouts.idea')

@section('title', 'Memory graph — IdeaTub')

@section('content')
<div class="max-w-6xl mx-auto px-6 md:px-8 pt-16 pb-24 space-y-4">
    <div>
        <h1 class="text-[22px] font-semibold text-deep-indigo">Memory graph</h1>
        <p class="text-xs text-slate-brand/70 mt-1 max-w-prose">Explore filtered subsets of your thoughts. Start with explicit links only for best performance.</p>
        <p class="text-xs text-slate-brand/60 mt-1"><a href="{{ route('help.memory-graph') }}" class="text-memory-violet hover:underline">Feature flags &amp; help</a></p>
    </div>

    <form id="vault-graph-filters" class="grid gap-3 rounded-2xl border border-memory-violet/15 bg-white/70 p-4 text-xs text-slate-brand sm:grid-cols-2 lg:grid-cols-3">
        <label class="flex flex-col gap-1">
            <span class="font-medium">Project</span>
            <select name="project_id" class="rounded-lg border border-memory-violet/20 bg-white px-2 py-1.5 text-deep-indigo">
                <option value="">All projects</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}">{{ $project->title }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1">
            <span class="font-medium">Tag</span>
            <input type="text" name="tag" placeholder="e.g. decision:foo" class="rounded-lg border border-memory-violet/20 bg-white px-2 py-1.5 text-deep-indigo" />
        </label>
        <label class="flex flex-col gap-1">
            <span class="font-medium">Source</span>
            <input type="text" name="source" placeholder="web, email, …" class="rounded-lg border border-memory-violet/20 bg-white px-2 py-1.5 text-deep-indigo" />
        </label>
        <fieldset class="sm:col-span-2 lg:col-span-3 flex flex-wrap gap-3 border-0 p-0 m-0">
            <legend class="sr-only">Layers</legend>
            <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="layers[]" value="thought_link" checked class="rounded border-memory-violet/30 text-memory-violet" /> Links</label>
            <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="layers[]" value="parent_child" class="rounded border-memory-violet/30 text-memory-violet" /> Sections</label>
            <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="layers[]" value="shared_tag" class="rounded border-memory-violet/30 text-memory-violet" /> Shared tags</label>
            @if ($showSemanticLayer ?? false)
                <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="layers[]" value="semantic" class="rounded border-memory-violet/30 text-memory-violet" /> Similar</label>
            @endif
            <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="include_neighbors" value="1" class="rounded border-memory-violet/30 text-memory-violet" /> Neighbors</label>
            <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="include_chunks" value="1" class="rounded border-memory-violet/30 text-memory-violet" /> Chunks</label>
        </fieldset>
        <div class="sm:col-span-2 lg:col-span-3 flex gap-3">
            <button type="submit" class="rounded-lg bg-memory-violet px-4 py-2 text-sm font-medium text-white hover:opacity-90">Load graph</button>
            <button type="button" id="vault-preset-links" class="text-memory-violet hover:underline">Links only (fast)</button>
        </div>
    </form>

    <p id="vault-graph-warning" class="hidden text-sm text-amber-700/90"></p>

    <div id="vault-graph-canvas" class="w-full rounded-2xl border border-memory-violet/20 bg-white/80 shadow-[0_4px_24px_rgba(109,106,247,0.08)]" style="min-height: 420px; height: min(72vh, 900px);"></div>
    <p id="vault-graph-empty" class="hidden text-sm text-slate-brand/70">No thoughts match these filters.</p>
</div>

@push('scripts')
<script src="https://unpkg.com/vis-network@9.1.9/standalone/umd/vis-network.min.js"></script>
<script>
(function () {
    const form = document.getElementById('vault-graph-filters');
    const container = document.getElementById('vault-graph-canvas');
    const emptyMsg = document.getElementById('vault-graph-empty');
    const warning = document.getElementById('vault-graph-warning');
    const baseUrl = @json(route('graph.vault.data'));
    if (!form || !container || typeof vis === 'undefined') return;

    let network = null;

    function buildUrl() {
        const params = new URLSearchParams(new FormData(form));
        const qs = params.toString();
        return qs ? baseUrl + '?' + qs : baseUrl;
    }

    function render(data) {
        const nodes = new vis.DataSet((data.nodes || []).map(function (n) {
            const group = n.group || 'member';
            const colors = { hub: { background: '#ecfdf5', border: '#2dd4bf' }, member: { background: '#f5f3ff', border: '#c4b5fd' }, chunk: { background: '#f1f5f9', border: '#94a3b8' } };
            const c = colors[group] || colors.member;
            return { id: n.id, label: n.label || n.id, shape: group === 'hub' ? 'ellipse' : 'box', color: { background: c.background, border: c.border } };
        }));
        const edges = new vis.DataSet((data.edges || []).map(function (e, i) {
            return { id: e.id || ('e'+i), from: e.from, to: e.to, label: e.label || '', dashes: e.edge_type === 'semantic' || e.dashed, arrows: e.directed === false ? '' : 'to' };
        }));

        if (nodes.length === 0) {
            emptyMsg.classList.remove('hidden');
            if (network) { network.destroy(); network = null; }
            return;
        }
        emptyMsg.classList.add('hidden');

        const warnings = (data.meta && data.meta.warnings) || [];
        const truncated = data.meta && data.meta.truncated;
        if (warnings.length || truncated) {
            warning.textContent = [truncated ? 'Results truncated at cap.' : '', warnings.join(' ')].filter(Boolean).join(' ');
            warning.classList.remove('hidden');
        } else {
            warning.classList.add('hidden');
        }

        if (network) network.setData({ nodes: nodes, edges: edges });
        else {
            network = new vis.Network(container, { nodes: nodes, edges: edges }, {
                physics: { stabilization: { iterations: 120 } },
                interaction: { hover: true },
            });
            network.once('stabilizationIterationsDone', function () { network.fit({ padding: 48, animation: false }); });
            network.on('doubleClick', function (p) {
                if (p.nodes.length && String(p.nodes[0]).indexOf('tag:') !== 0) window.location.href = @json(url('/thoughts')) + '/' + p.nodes[0];
            });
        }
        network.fit({ padding: 48, animation: false });
    }

    function load(e) {
        if (e) e.preventDefault();
        fetch(buildUrl(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () { emptyMsg.textContent = 'Could not load graph.'; emptyMsg.classList.remove('hidden'); });
    }

    form.addEventListener('submit', load);
    document.getElementById('vault-preset-links').addEventListener('click', function () {
        form.querySelectorAll('input[type="checkbox"]').forEach(function (el) { el.checked = el.name === 'layers[]' && el.value === 'thought_link'; });
        form.querySelector('[name="project_id"]').value = '';
        form.querySelector('[name="tag"]').value = '';
        form.querySelector('[name="source"]').value = '';
        load();
    });

    load();
})();
</script>
@endpush
@endsection
