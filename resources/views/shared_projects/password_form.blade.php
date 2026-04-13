@extends('layouts.minimal')

@section('title', 'Enter password to view')

@section('content')
<div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)] max-w-md mx-auto">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-2">Protected</p>
    <h2 class="text-lg font-semibold text-deep-indigo mb-4">Enter password to view</h2>
    <form method="POST" action="{{ $postUrl }}" class="space-y-4">
        @csrf
        <div>
            <label for="password" class="block text-sm font-medium text-deep-indigo mb-1">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password"
                   class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2.5 text-sm text-deep-indigo placeholder-slate-brand/50 focus:border-neural-teal focus:ring-2 focus:ring-memory-violet/30 outline-none transition"
                   placeholder="Enter password">
            @if(isset($error))
                <p class="mt-1 text-xs text-red-600">{{ $error }}</p>
            @endif
        </div>
        <button type="submit" class="w-full rounded-lg text-sm font-medium py-2.5 px-4 text-white transition focus:ring-2 focus:ring-memory-violet/30 outline-none" style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
            View
        </button>
    </form>
</div>
@endsection
