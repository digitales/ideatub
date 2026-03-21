@extends('layouts.idea')

@section('title', 'Thought — IdeaTub')

@section('content')
@php
    $isEmailThought = $thought->source === 'email';
    $bodyText = $isEmailThought && $importedEmail?->body_text
        ? $importedEmail->body_text
        : $thought->content;
@endphp

<div class="max-w-6xl mx-auto px-6 md:px-8 pt-16 pb-24 space-y-6">
    @include('idea.partials.thought_detail_header', ['thought' => $thought])

    <div class="{{ $isEmailThought ? 'grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)] lg:items-start' : '' }}">
        <article class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">
                {{ $isEmailThought ? 'Email body' : 'Content' }}
            </p>
            <div class="text-[14px] md:text-[15px] text-deep-indigo leading-relaxed whitespace-pre-line">
                {{ $bodyText }}
            </div>
        </article>

        @if ($isEmailThought)
            @include('idea.partials.thought_detail_email_sidebar', ['thought' => $thought, 'importedEmail' => $importedEmail])
        @endif
    </div>

    @include('idea.partials.thought_detail_replies', ['thought' => $thought])
</div>
@endsection
