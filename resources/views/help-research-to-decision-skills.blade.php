@extends('layouts.idea')

@section('title', 'Research-to-decision skills — Help — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <p class="text-sm text-slate-brand mb-4">
        <a href="{{ route('help.research-to-decision') }}" class="text-memory-violet hover:underline">← Research-to-decision workflow</a>
    </p>
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Research-to-decision skills</h1>
    <p class="text-sm text-slate-brand mb-6">OB1 skill packs adapted for IdeaTub (prefilled MCP and Help URLs). View in the browser or download for Claude Code, Cursor, or other clients.</p>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <h2 class="text-base font-semibold text-deep-indigo mb-3">Download bundle</h2>
        <p class="text-sm text-slate-brand mb-4">ZIP includes each skill’s <code class="bg-memory-violet/10 px-1 rounded text-xs">SKILL.md</code>, the bundle <strong>README</strong>, and <strong>THIRD_PARTY_OB1.md</strong> (license notice).</p>
        <a href="{{ route('help.research-to-decision.skills.zip') }}" class="inline-flex items-center gap-2 rounded-lg bg-memory-violet/15 px-4 py-2 text-sm font-medium text-memory-violet hover:bg-memory-violet/25 transition-colors">Download ZIP</a>
    </div>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <h2 class="text-base font-semibold text-deep-indigo mb-3">Skills</h2>
        <table class="w-full text-sm text-deep-indigo border-collapse">
            <thead>
                <tr class="border-b border-memory-violet/15">
                    <th class="text-left py-2 font-medium">Skill</th>
                    <th class="text-right py-2 font-medium w-[1%] whitespace-nowrap">View</th>
                    <th class="text-right py-2 font-medium w-[1%] whitespace-nowrap">Download</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-memory-violet/10">
                @foreach($skills as $row)
                <tr>
                    <td class="py-2.5 text-slate-brand">{{ $row['label'] }}</td>
                    <td class="py-2.5 text-right">
                        @if($row['missing'])
                        <span class="text-slate-brand/50 text-xs">Missing</span>
                        @else
                        <a href="{{ route('help.research-to-decision.skills.show', $row['slug']) }}" class="text-memory-violet hover:underline">Open</a>
                        @endif
                    </td>
                    <td class="py-2.5 text-right">
                        @if($row['missing'])
                        <span class="text-slate-brand/50 text-xs">—</span>
                        @else
                        <a href="{{ route('help.research-to-decision.skills.download-one', $row['slug']) }}" class="text-memory-violet hover:underline">.md</a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="text-xs text-slate-brand/80 mt-8">
        Third-party terms: <a href="{{ route('help.third-party.ob1') }}" class="text-memory-violet hover:underline">OB1 (FSL-1.1-MIT)</a> (also included in the ZIP). MCP setup: <a href="{{ route('help') }}#mcp" class="text-memory-violet hover:underline">Help → MCP integration</a>.
    </p>
</div>
@endsection
