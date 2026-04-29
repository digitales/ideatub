@extends('layouts.idea')

@section('title', 'Third-party notice: OB1 — Help — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <p class="text-sm text-slate-brand mb-4">
        <a href="{{ route('help.research-to-decision.skills.index') }}" class="text-memory-violet hover:underline">← Research-to-decision skills</a>
    </p>
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Third-party notice: OB1</h1>
    <p class="text-sm text-slate-brand mb-8">License and redistribution pointers for bundled Research-to-Decision skills.</p>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-p:text-slate-brand prose-li:text-slate-brand prose-strong:text-deep-indigo prose-a:text-memory-violet prose-table:text-sm prose-th:text-deep-indigo prose-td:text-slate-brand">
            {!! $bodyHtml !!}
        </div>
    </div>
</div>
@endsection
