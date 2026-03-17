@extends('layouts.minimal')

@section('title', 'Enter password to view')

@section('content')
<div class="rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-6">
    <h2 class="text-lg font-semibold text-deep-indigo mb-4">Enter password to view</h2>
    <form method="POST" action="{{ route('shared-research.show', $token) }}" class="space-y-4">
        @csrf
        <div>
            <label for="password" class="block text-xs font-medium text-slate-brand mb-1">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password"
                   class="w-full rounded-lg border border-memory-violet/20 bg-white/60 px-3 py-2.5 text-sm text-deep-indigo placeholder-slate-brand/40 focus:border-memory-violet/50 focus:ring-2 focus:ring-memory-violet/20 outline-none transition"
                   placeholder="Enter password">
            @if(isset($error))
                <p class="mt-1 text-xs text-red-500">{{ $error }}</p>
            @endif
        </div>
        <button type="submit" class="w-full rounded-lg bg-memory-violet text-white text-sm font-medium py-2.5 px-4 hover:bg-memory-violet/90 focus:ring-2 focus:ring-memory-violet/30 transition">
            View
        </button>
    </form>
</div>
@endsection
