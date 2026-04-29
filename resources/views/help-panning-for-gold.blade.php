@extends('layouts.idea')

@section('title', 'Panning for Gold prompts — Help — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <p class="text-sm text-slate-brand mb-4">
        <a href="{{ route('help') }}#mcp" class="text-memory-violet hover:underline">← Help → MCP integration</a>
    </p>
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Panning for Gold prompts</h1>
    <p class="text-sm text-slate-brand mb-6">Repository prompts for turning meetings and brain dumps into inventory + gold-found markdown, then capturing via IdeaTub MCP. Read the <strong>core</strong> file plus <strong>one</strong> wrapper (meeting or brain-dump).</p>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <h2 class="text-base font-semibold text-deep-indigo mb-3">Download bundle</h2>
        <p class="text-sm text-slate-brand mb-4">ZIP matches a repo checkout: <code class="bg-memory-violet/10 px-1 rounded text-xs">.cursor/rules/panning-for-gold.mdc</code>, three prompts under <code class="bg-memory-violet/10 px-1 rounded text-xs">resources/prompts/</code>, and <strong>CURSOR-BUNDLE.txt</strong>. Unzip at project root and merge folders.</p>
        <a href="{{ route('help.panning-for-gold.zip') }}" class="inline-flex items-center gap-2 rounded-lg bg-memory-violet/15 px-4 py-2 text-sm font-medium text-memory-violet hover:bg-memory-violet/25 transition-colors">Download ZIP</a>
    </div>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <h2 class="text-base font-semibold text-deep-indigo mb-3">Prompts</h2>
        <table class="w-full text-sm text-deep-indigo border-collapse">
            <thead>
                <tr class="border-b border-memory-violet/15">
                    <th class="text-left py-2 font-medium">File</th>
                    <th class="text-right py-2 font-medium w-[1%] whitespace-nowrap">View</th>
                    <th class="text-right py-2 font-medium w-[1%] whitespace-nowrap">Download</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-memory-violet/10">
                @foreach($prompts as $row)
                <tr>
                    <td class="py-2.5">
                        <span class="text-deep-indigo font-medium">{{ $row['label'] }}</span>
                        <span class="block text-xs text-slate-brand/90 mt-0.5 font-mono">{{ $row['filename'] }}</span>
                    </td>
                    <td class="py-2.5 text-right">
                        @if($row['missing'])
                        <span class="text-slate-brand/50 text-xs">Missing</span>
                        @else
                        <a href="{{ route('help.panning-for-gold.show', $row['slug']) }}" class="text-memory-violet hover:underline">Open</a>
                        @endif
                    </td>
                    <td class="py-2.5 text-right">
                        @if($row['missing'])
                        <span class="text-slate-brand/50 text-xs">—</span>
                        @else
                        <a href="{{ route('help.panning-for-gold.download-one', $row['slug']) }}" class="text-memory-violet hover:underline">.md</a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @include('partials.help-cursor-agent-instructions', ['variant' => 'pfg'])

    <p class="text-xs text-slate-brand/80 mt-8">
        Upstream methodology: <a href="https://github.com/NateBJones-Projects/OB1/tree/main/recipes/panning-for-gold" class="text-memory-violet hover:underline" target="_blank" rel="noopener noreferrer">OB1 — panning-for-gold</a>. Design: <code class="bg-memory-violet/10 px-1 rounded text-[11px]">docs/superpowers/specs/2026-04-29-panning-for-gold-ideatub-design.md</code>.
    </p>
</div>
@endsection
