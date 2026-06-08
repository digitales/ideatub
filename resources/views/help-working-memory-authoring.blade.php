@extends('layouts.idea')

@section('title', 'Working memory authoring — Help — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <p class="text-sm text-slate-brand mb-4">
        <a href="{{ route('help') }}#mcp" class="text-memory-violet hover:underline">← Help → MCP integration</a>
    </p>
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Working memory authoring</h1>
    <p class="text-sm text-slate-brand mb-6">Judgment-first prompts for refreshing working memory via MCP. Read the <strong>core</strong> spec, then the <strong>agent</strong> wrapper for the MCP workflow (<code class="bg-memory-violet/10 px-1 rounded text-xs">get_working_memory</code> → <code class="bg-memory-violet/10 px-1 rounded text-xs">search_thoughts</code> → <code class="bg-memory-violet/10 px-1 rounded text-xs">upsert_working_memory</code>).</p>

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
                        <a href="{{ route('help.working-memory-authoring.show', $row['slug']) }}" class="text-memory-violet hover:underline">Open</a>
                        @endif
                    </td>
                    <td class="py-2.5 text-right">
                        @if($row['missing'])
                        <span class="text-slate-brand/50 text-xs">—</span>
                        @else
                        <a href="{{ route('help.working-memory-authoring.download-one', $row['slug']) }}" class="text-memory-violet hover:underline">.md</a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="text-xs text-slate-brand/80 mt-8">
        Related: <a href="{{ route('help.working-memory-corpus-sync') }}" class="text-memory-violet hover:underline">Working memory corpus sync</a>.
        Design: <code class="bg-memory-violet/10 px-1 rounded text-[11px]">docs/superpowers/specs/2026-06-08-working-memory-judgment-first-authoring-design.md</code>.
    </p>
</div>
@endsection
