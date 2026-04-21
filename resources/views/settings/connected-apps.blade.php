@extends('layouts.idea')

@section('title', 'Connected apps — IdeaTub')

@section('content')
<div class="max-w-[720px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Connected apps</h1>
    <p class="text-sm text-slate-brand mb-8">OAuth-connected AI tools like Claude and ChatGPT. Revoking a connection forces the client to go through consent again.</p>

    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <div class="flex items-start justify-between gap-4 mb-4">
            <h2 class="text-lg font-semibold text-deep-indigo">Your connected apps</h2>
            @if ($families->isNotEmpty())
                <form method="POST" action="{{ route('settings.connected-apps.destroy-all') }}" onsubmit="return confirm('Disconnect all connected apps? Each will need to re-consent.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 hover:underline">Disconnect all</button>
                </form>
            @endif
        </div>

        @if ($families->isEmpty())
            <p class="text-sm text-slate-brand">No OAuth-connected apps. Claude, ChatGPT, and other MCP clients you connect will appear here.</p>
        @else
            <ul class="space-y-4">
                @foreach ($families as $family)
                    @php
                        $host = parse_url($family->client->redirect_uris[0] ?? '', PHP_URL_HOST) ?: \Illuminate\Support\Str::limit($family->client_id, 16);
                    @endphp
                    <li class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-memory-violet/10 bg-white/60 px-4 py-3">
                        <div class="min-w-0 flex-1 space-y-1">
                            <p class="text-sm font-semibold text-deep-indigo">{{ $host }}</p>
                            <p class="text-[11px] text-slate-brand">Scope: <code class="bg-white/80 px-1 rounded">{{ $family->scope ?? 'ideatub:mcp' }}</code></p>
                            <p class="text-[11px] text-slate-brand">Connected {{ $family->issued_at->diffForHumans() }} · Expires {{ $family->absolute_expires_at->diffForHumans() }}</p>
                            @if ($family->last_used_at)
                                <p class="text-[11px] text-slate-brand/70">Last used {{ $family->last_used_at->diffForHumans() }}@if ($family->ip_address) · IP {{ $family->ip_address }}@endif</p>
                            @endif
                            @if ($family->user_agent)
                                <p class="text-[11px] text-slate-brand/60 truncate">{{ $family->user_agent }}</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('settings.connected-apps.destroy', $family) }}" class="shrink-0 inline" onsubmit="return confirm('Disconnect this app? It will need to re-consent.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 hover:underline">Disconnect</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
