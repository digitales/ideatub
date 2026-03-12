@extends('layouts.idea')

@section('title', 'Connect to IdeaTub — OAuth')

@section('content')
<div class="max-w-[400px] mx-auto px-6 pt-16 pb-24">
    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <h1 class="text-xl font-semibold text-deep-indigo mb-2">Connect to IdeaTub</h1>
        <p class="text-sm text-slate-brand mb-4">An app (e.g. ChatGPT) is requesting access to your IdeaTub thoughts so it can search and capture on your behalf.</p>
        <p class="text-xs text-slate-brand/80 mb-6">Scope: <code class="bg-memory-violet/10 px-1 rounded">{{ $scope }}</code></p>
        <div class="flex gap-3">
            <a href="{{ $authorizeUrl }}" class="flex-1 text-center text-sm font-medium text-white px-4 py-2.5 rounded-lg transition-opacity hover:opacity-90" style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
                Allow
            </a>
            <a href="{{ route('idea.index') }}" class="flex-1 text-center text-sm font-medium text-slate-brand border border-memory-violet/20 px-4 py-2.5 rounded-lg hover:bg-memory-violet/5 transition-colors">
                Cancel
            </a>
        </div>
    </div>
</div>
@endsection
