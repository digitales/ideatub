@extends('layouts.idea')

@section('title', 'Repo Learning Coach — Help — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <p class="text-sm text-slate-brand mb-4">
        <a href="{{ route('help') }}" class="text-memory-violet hover:underline">← Help</a>
    </p>
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Repo Learning Coach</h1>
    <p class="text-sm text-slate-brand mb-6">
        Markdown curriculum and research synced into IdeaTub, with lessons under <code class="bg-memory-violet/10 px-1 rounded text-xs">/learn</code>, capture into thoughts, and optional quizzes and progress.
    </p>

    <div class="rounded-2xl border border-memory-violet/20 bg-memory-violet/10 p-4 mb-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <p class="text-sm text-deep-indigo font-medium mb-1">Open the app</p>
        <p class="text-sm text-slate-brand mb-3">Learning UI is authenticated. After you sync content for your user, open your projects from Learn.</p>
        <a href="{{ url('/learn/projects') }}" class="inline-flex items-center gap-2 rounded-lg bg-memory-violet/20 px-4 py-2 text-sm font-medium text-memory-violet hover:bg-memory-violet/30 transition-colors">Go to Learn →</a>
    </div>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-p:text-slate-brand prose-li:text-slate-brand prose-strong:text-deep-indigo prose-a:text-memory-violet prose-table:text-sm prose-th:text-deep-indigo prose-td:text-slate-brand prose-pre:bg-slate-100 prose-pre:text-deep-indigo prose-code:text-deep-indigo">
            {!! $bodyHtml !!}
        </div>
    </div>

    <p class="text-xs text-slate-brand/80 mt-8">
        MCP capture patterns: <a href="{{ route('help') }}#mcp" class="text-memory-violet hover:underline">Help → MCP integration</a>.
    </p>
</div>
@endsection
