@extends('layouts.idea')

@section('title', 'Research-to-decision workflow — Help — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <p class="text-sm text-slate-brand mb-4">
        <a href="{{ route('help') }}" class="text-memory-violet hover:underline">← Help</a>
    </p>
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Research-to-decision workflow</h1>
    <p class="text-sm text-slate-brand mb-8">Use the OB1 Research-to-Decision recipe with IdeaTub MCP: search prior notes, capture each step, and review in Stream.</p>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-p:text-slate-brand prose-li:text-slate-brand prose-strong:text-deep-indigo prose-a:text-memory-violet prose-table:text-sm prose-th:text-deep-indigo prose-td:text-slate-brand prose-pre:bg-slate-100 prose-pre:text-deep-indigo prose-code:text-deep-indigo">
            {!! $bodyHtml !!}
        </div>
    </div>

    <p class="text-xs text-slate-brand/80 mt-8">
        MCP connection details: <a href="{{ route('help') }}#mcp" class="text-memory-violet hover:underline">Help → MCP integration</a>.
    </p>
</div>
@endsection
