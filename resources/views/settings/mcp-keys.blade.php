@extends('layouts.idea')

@section('title', 'MCP key — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">MCP key</h1>
    <p class="text-sm text-slate-brand mb-8">Use this key to connect Claude, Cursor, ChatGPT, or other AI tools to your IdeaTub. See the <a href="{{ route('help') }}#mcp" class="text-memory-violet hover:underline">integration guide</a> in Help.</p>

    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    {{-- One-time display of new key (after create) --}}
    @if ($newKey ?? null)
        <div
            x-data="{ copied: false }"
            class="mb-8 rounded-2xl border-2 border-neural-teal/30 bg-neural-teal/5 p-6 shadow-[0_4px_24px_rgba(42,140,140,0.12)]"
        >
            <p class="text-[11px] font-semibold uppercase tracking-wider text-neural-teal/80 mb-2">Your new MCP key — copy it now</p>
            <p class="text-xs text-slate-brand mb-3">This is the only time you’ll see it. Store it somewhere safe (e.g. password manager).</p>
            <div class="flex flex-wrap items-center gap-2">
                <code class="flex-1 min-w-0 text-sm font-mono text-deep-indigo bg-white/80 border border-memory-violet/15 rounded-lg px-3 py-2 break-all select-all" id="new-mcp-key">{{ $newKey }}</code>
                <button
                    type="button"
                    @click="navigator.clipboard.writeText(document.getElementById('new-mcp-key').innerText); copied = true; setTimeout(() => copied = false, 2000)"
                    class="shrink-0 text-xs font-medium text-white px-3 py-2 rounded-lg transition-opacity hover:opacity-90"
                    style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                >
                    <span x-show="!copied">Copy</span>
                    <span x-show="copied" x-cloak>Copied</span>
                </button>
            </div>
            <p class="mt-3 text-[11px] text-slate-brand/80">Connection URL: <code class="bg-white/60 px-1.5 py-0.5 rounded">{{ $mcpUrl }}?key=<span class="select-all">{{ $newKey }}</span></code></p>
        </div>
    @endif

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <h2 class="text-lg font-semibold text-deep-indigo mb-4">Your MCP keys</h2>
        @if ($keys->isEmpty())
            <p class="text-sm text-slate-brand mb-4">You don’t have an MCP key yet. Create one to connect your AI tools.</p>
        @else
            <ul class="space-y-4 mb-4">
                @foreach ($keys as $key)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-memory-violet/10 bg-white/60 px-4 py-3">
                        <div>
                            <code class="text-sm font-mono text-deep-indigo">ideatub_••••••••••••••••••••••••••••••••</code>
                            @if ($key->label)
                                <span class="ml-2 text-xs text-slate-brand">{{ $key->label }}</span>
                            @endif
                            @if ($key->last_used_at)
                                <p class="text-[11px] text-slate-brand/70 mt-1">Last used {{ $key->last_used_at->diffForHumans() }}</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('settings.mcp-keys.destroy', $key) }}" class="inline" onsubmit="return confirm('Revoke this key? Clients using it will stop working until you add a new key.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 hover:underline">Revoke</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
        <form method="POST" action="{{ route('settings.mcp-keys.store') }}">
            @csrf
            <button
                type="submit"
                class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
            >
                {{ $keys->isEmpty() ? 'Create MCP key' : 'Create another key' }}
            </button>
        </form>
    </div>

    <div class="rounded-2xl border border-memory-violet/15 bg-white/60 p-4 text-sm text-slate-brand">
        <p class="font-medium text-deep-indigo mb-1">Endpoint</p>
        <code class="text-xs break-all">{{ $mcpUrl }}</code>
        <p class="mt-2 text-[11px]">Send your key as <code class="bg-white/80 px-1 rounded">?key=YOUR_KEY</code> or in the <code class="bg-white/80 px-1 rounded">x-ideatub-key</code> header. Use the same key in every AI client.</p>
    </div>
</div>
@endsection
