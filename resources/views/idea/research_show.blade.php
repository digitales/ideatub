@extends('layouts.idea')

@section('title', ($pageTitle ?? Str::limit($root->content, 50)) . ' — IdeaTub')

@section('content')
<div class="max-w-4xl mx-auto px-6 md:px-8 pt-16 pb-24">
    <p class="mb-4">
        <a href="{{ request()->query('from') === 'ideas' ? route('idea.ideas') : route('idea.stream') }}" class="text-[12px] font-medium text-memory-violet hover:underline">
            {{ request()->query('from') === 'ideas' ? '← Back to Ideas' : '← Back to Stream' }}
        </a>
    </p>
    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Research</p>
        @if (! empty($linkedVideo ?? null))
            <div class="mb-6 rounded-xl border border-rose-400/25 bg-rose-500/[0.06] p-4 md:p-5">
                <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-rose-600/90 mb-3">Related video</p>
                <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-2">Video metadata</p>
                @include('idea.partials.video_metadata_labeled_rows', ['rows' => $linkedVideo['metadata_rows'] ?? []])
                <p class="mt-3">
                    <a href="{{ $linkedVideo['detail_url'] }}" class="text-[13px] font-medium text-memory-violet hover:underline">Open video thought</a>
                </p>
            </div>
        @endif
        @if (! empty($relatedEmail))
            <div class="mb-6 rounded-xl border border-memory-violet/25 bg-memory-violet/5 p-4 md:p-5">
                <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Related email</p>
                <p class="text-[14px] md:text-[15px] font-semibold text-deep-indigo">{{ $relatedEmail['subject'] }}</p>
                <p class="text-[13px] text-slate-brand mt-1">{{ $relatedEmail['sender'] }}</p>
                <p class="mt-3">
                    <a href="{{ $relatedEmail['url'] }}" class="text-[13px] font-medium text-memory-violet hover:underline">View email</a>
                </p>
            </div>
        @endif
        @include('idea.partials.research_newsletter_analysis', ['newsletterAnalysis' => $newsletterAnalysis ?? null])
        @include('idea.partials.research_editorial_link_summaries', ['editorialLinkSummaries' => $editorialLinkSummaries])
        @push('research-after-root')
            <p class="text-[11px] text-slate-brand/50 mt-4">{{ $root->created_at->diffForHumans() }}</p>
        @endpush
        @include('idea.partials.research_content', [
            'root_html' => $root_html,
            'sections' => $sections,
            'researchContentComments' => $researchContentComments,
        ])
    </div>
    @if(isset($commentsPresenter))
        @if(($researchUnreadBannerCount ?? 0) > 0)
            <p class="mt-6 text-[12px] font-semibold text-memory-violet">
                {{ $researchUnreadBannerCount }} new comment(s) since your last visit.
            </p>
        @endif
        <div class="mt-8">
            @include('comments._thread', [
                'rows' => $commentsPresenter->pageLevelRows(),
                'formAction' => route('comments.store'),
                'commentableType' => 'thought',
                'commentableId' => $root->id,
                'mode' => 'owner',
                'disabledMessage' => $commentsPresenter->canCommentOnPage() ? null : 'Comments are disabled.',
                'title' => 'Comments',
                'showControls' => true,
            ])
        </div>
    @endif
</div>
@endsection
