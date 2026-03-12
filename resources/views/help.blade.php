@extends('layouts.idea')

@section('title', 'Help — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Help</h1>
    <p class="text-sm text-slate-brand mb-8">Keyboard shortcuts for the thinking space.</p>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <h2 class="text-lg font-semibold text-deep-indigo mb-4">Keyboard shortcuts</h2>
        <table class="w-full text-sm text-deep-indigo">
            <tbody class="divide-y divide-memory-violet/10">
                <tr><td class="py-2">Focus capture</td><td class="py-2 text-right text-slate-brand font-medium">⌘/ or Ctrl+/</td></tr>
                <tr><td class="py-2">Open search</td><td class="py-2 text-right text-slate-brand font-medium">⌘K or Ctrl+K</td></tr>
                <tr><td class="py-2">Move down / up thought</td><td class="py-2 text-right text-slate-brand font-medium">j / k</td></tr>
                <tr><td class="py-2">Open reply</td><td class="py-2 text-right text-slate-brand font-medium">Enter</td></tr>
                <tr><td class="py-2">Cancel reply / close search</td><td class="py-2 text-right text-slate-brand font-medium">Escape</td></tr>
                <tr><td class="py-2">Submit thought</td><td class="py-2 text-right text-slate-brand font-medium">⌘+Enter or Ctrl+Enter</td></tr>
                <tr><td class="py-2">Show shortcut list</td><td class="py-2 text-right text-slate-brand font-medium">?</td></tr>
            </tbody>
        </table>
    </div>
</div>
@endsection
