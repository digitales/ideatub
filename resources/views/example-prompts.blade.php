@extends('layouts.idea')

@section('title', 'Example Prompts — IdeaTub')

@section('content')
<div class="max-w-[680px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Example Prompts</h1>
    <p class="text-sm text-slate-brand mb-8">
        Companion prompts for your Open Brain: migrate memories, bring over your second brain, discover use cases, use quick-capture templates, and run a weekly review.
        <a href="{{ $source_url }}" target="_blank" rel="noopener noreferrer" class="text-memory-violet hover:underline">Prompt Kit source</a>
    </p>

    @foreach($prompts as $prompt)
    <section class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-p:text-slate-brand prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100 prose-pre:text-deep-indigo prose-code:text-deep-indigo">
            {!! $prompt['body_html'] !!}
        </div>
    </section>
    @endforeach

    <p class="text-xs text-slate-brand/70 mt-6">
        From <a href="{{ $source_url }}" target="_blank" rel="noopener noreferrer" class="text-memory-violet hover:underline">Open Brain: Companion Prompts</a> (Prompt Kit by Nate B. Jones).
    </p>
</div>
@endsection
