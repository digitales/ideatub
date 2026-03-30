@extends('layouts.app')

@section('title', config('app.name', 'IdeaTub') . ' — Capture and search your ideas')
@section('description', 'IdeaTub is your thinking space. Capture thoughts, find them with semantic search. Use in the browser or via MCP.')

@section('schema')
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "WebApplication",
    "name": "{{ config('app.name', 'IdeaTub') }}",
    "description": "Capture and search thoughts with semantic search. Use in the browser or via MCP.",
    "url": "{{ url('/') }}",
    "applicationCategory": "ProductivityApplication",
    "operatingSystem": "Any",
    "offers": {
        "@@type": "Offer",
        "price": "0",
        "priceCurrency": "USD"
    }
}
</script>
@endsection

@section('content')
<div class="min-h-[80vh] flex flex-col">
    {{-- Hero --}}
    <div class="flex-1 flex flex-col items-center justify-center px-4 py-16 text-center" style="background: linear-gradient(135deg, #eef2ff 0%, #f3f0ff 50%, #f0f5ff 100%);">
        <p class="text-[11px] font-semibold tracking-[0.12em] uppercase text-memory-violet mb-3">Your thinking space</p>
        <h1 class="text-4xl font-semibold text-deep-indigo sm:text-5xl md:text-6xl leading-tight max-w-3xl mx-auto">
            Capture ideas. Find them by meaning.
        </h1>
        <p class="mt-4 text-lg text-slate-brand max-w-xl mx-auto">
            A calm place to store thoughts and search them with semantic search. Use the web app or connect via MCP.
        </p>
        <div class="mt-10 flex flex-wrap items-center justify-center gap-4">
            <a href="{{ route('register') }}" class="inline-flex items-center px-6 py-3 rounded-lg text-sm font-medium text-white transition hover:opacity-90" style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
                Get started
            </a>
            <a href="{{ route('login') }}" class="inline-flex items-center px-6 py-3 rounded-lg text-sm font-medium text-slate-brand bg-white/80 border border-memory-violet/20 hover:bg-white hover:border-memory-violet/40 transition">
                Sign in
            </a>
        </div>
    </div>

    {{-- Value props --}}
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-slate-brand/60 text-center mb-10">Why IdeaTub</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="rounded-2xl border border-memory-violet/10 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.06)]">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-4 text-neural-teal" style="background: rgba(42,140,140,0.12);">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.2-5.2m0 0a7.5 7.5 0 1 0-10.6-10.6 7.5 7.5 0 0 0 10.6 10.6Z"/></svg>
                </div>
                <h3 class="text-lg font-semibold text-deep-indigo mb-2">Semantic search</h3>
                <p class="text-sm text-slate-brand leading-relaxed">Find thoughts by meaning, not just keywords. Ask in plain language and get relevant ideas back.</p>
            </div>
            <div class="rounded-2xl border border-memory-violet/10 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.06)]">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-4 text-memory-violet" style="background: rgba(109,106,247,0.12);">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </div>
                <h3 class="text-lg font-semibold text-deep-indigo mb-2">Capture anywhere</h3>
                <p class="text-sm text-slate-brand leading-relaxed">Quick capture in the browser or from any tool that supports MCP. Thoughts stay in one place.</p>
            </div>
            <div class="rounded-2xl border border-memory-violet/10 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.06)]">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-4 text-memory-violet" style="background: rgba(109,106,247,0.12);">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/></svg>
                </div>
                <h3 class="text-lg font-semibold text-deep-indigo mb-2">Browser & MCP</h3>
                <p class="text-sm text-slate-brand leading-relaxed">Capture from Claude, Cursor, or other MCP clients, then revisit everything in the web app.</p>
            </div>
        </div>
    </div>
</div>
@endsection
