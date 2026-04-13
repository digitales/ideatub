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
    @if (session('success'))
        <div class="rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    @include('idea.partials.thought_detail_header', [
        'thought' => $thought,
        'thoughtDetail' => $thoughtDetail,
        'editable' => ! app(\App\Services\DemoMode::class)->enabled(),
    ])

    @include('idea.partials.thought_detail_projects_and_links', [
        'thought' => $thought,
        'thoughtProjectsForDetail' => $thoughtProjectsForDetail,
        'projectsToAttachToThought' => $projectsToAttachToThought,
        'thoughtOutgoingLinks' => $thoughtOutgoingLinks,
        'thoughtIncomingLinks' => $thoughtIncomingLinks,
        'linkTargetThoughtOptions' => $linkTargetThoughtOptions,
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
                @foreach ($thoughtDetailContentBlocks ?? [] as $block)
                    <div class="mb-8 last:mb-0">
                        @include('idea.partials.editable_thought_content', [
                            'thought' => $block['thought'],
                            'editable' => $block['editable'],
                            'displayContent' => '',
                            'rawEditorContent' => $block['editable'] ? $block['thought']->content : '',
                            'detailMarkdownRead' => true,
                            'contentHtml' => $block['content_html'],
                            'displayClass' => 'text-[14px] md:text-[15px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line break-words [overflow-wrap:anywhere]',
                            'editorClass' => 'w-full text-[14px] md:text-[15px] text-deep-indigo leading-relaxed rounded-lg border border-memory-violet/20 focus:border-memory-violet focus:ring-memory-violet/20',
                        ])
                    </div>
                @endforeach
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
