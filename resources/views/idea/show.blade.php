@extends('layouts.idea')

@section('title', 'Thought — IdeaTub')

@section('content')
@php
    $thought = $thoughtDetail->thought();
    $isEmailThought = $thoughtDetail->isEmailThought();
    $isVideoThought = $thoughtDetail->isVideoThought();
    $useThoughtDetailTwoColumn = $isEmailThought || $isVideoThought;
    $emailBodyText = $thoughtDetail->emailBodyText();
@endphp

<div class="max-w-6xl mx-auto px-6 md:px-8 pt-16 pb-24 space-y-6">
    @include('idea.partials.thought_detail_header', [
        'thought' => $thought,
        'thoughtDetail' => $thoughtDetail,
        'editable' => ! app(\App\Services\DemoMode::class)->enabled(),
    ])

    <div class="{{ $useThoughtDetailTwoColumn ? 'grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)] lg:items-start' : '' }}">
        @if ($useThoughtDetailTwoColumn)
            <div class="space-y-6 min-w-0" data-thought-detail-main>
        @endif
        <article class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">
                {{ $isEmailThought ? 'Email body' : 'Content' }}
            </p>
            @if ($isEmailThought)
                <div class="text-[14px] md:text-[15px] text-deep-indigo leading-relaxed whitespace-pre-line">
                    {{ $emailBodyText }}
                </div>
            @else
                <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[14px] md:text-[15px]">
                    {!! $thoughtDetail->contentHtml() !!}
                    @foreach ($thoughtDetail->documentSectionHtmlChunks() as $sectionHtml)
                        {!! $sectionHtml !!}
                    @endforeach
                </div>
            @endif
        </article>

        @if ($isVideoThought && ! empty($thoughtDetail->videoResearchPreview()))
            @include('idea.partials.thought_detail_research_preview_card', [
                'researchPreview' => $thoughtDetail->videoResearchPreview(),
            ])
        @endif

        @if ($thoughtDetail->isVideoThought() && $thoughtDetail->videoTranscriptText())
            <article class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
                <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Transcript</p>
                <div class="text-[14px] md:text-[15px] text-deep-indigo leading-relaxed whitespace-pre-line">
                    {{ $thoughtDetail->videoTranscriptText() }}
                </div>
            </article>
        @endif

        @if ($isEmailThought && ! empty($thoughtDetail->emailResearchPreview()))
            @include('idea.partials.thought_detail_research_preview_card', [
                'researchPreview' => $thoughtDetail->emailResearchPreview(),
            ])
        @endif

        @if ($useThoughtDetailTwoColumn)
            </div>
            @if ($isEmailThought)
                @include('idea.partials.thought_detail_email_sidebar', [
                    'thought' => $thought,
                    'emailMetadata' => $thoughtDetail->emailMetadata(),
                    'senderRuleContext' => $thoughtDetail->senderRuleContext(),
                    'newsletterResearchStatus' => $thoughtDetail->newsletterResearchStatus(),
                ])
            @endif
            @if ($isVideoThought)
                @include('idea.partials.thought_detail_video_sidebar', [
                    'thought' => $thought,
                    'thoughtDetail' => $thoughtDetail,
                    'editable' => ! app(\App\Services\DemoMode::class)->enabled(),
                    'videoCaptureReturnThoughtId' => $thought->id,
                ])
            @endif
        @endif
    </div>

    @include('idea.partials.thought_detail_replies', ['thoughtDetail' => $thoughtDetail])
</div>
@endsection
