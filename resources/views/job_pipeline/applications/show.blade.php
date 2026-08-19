@extends('layouts.idea')

@section('title', $application->role_title.' — '.$application->company->name.' — IdeaTub')

@section('content')
@php
    $stageBadgeClass = match ($application->stage) {
        'researching' => 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-600/40 dark:bg-slate-900/60 dark:text-slate-200',
        'applied' => 'border-memory-violet/20 bg-memory-violet/10 text-memory-violet',
        'screening' => 'border-amber-300/70 bg-amber-50 text-amber-900 dark:border-amber-400/30 dark:bg-amber-950/40 dark:text-amber-100',
        'interviewing' => 'border-sky-300/70 bg-sky-50 text-sky-900 dark:border-sky-400/30 dark:bg-sky-950/40 dark:text-sky-100',
        'offer' => 'border-neural-teal/30 bg-neural-teal/15 text-neural-teal',
        'rejected' => 'border-red-300/70 bg-red-50 text-red-800 dark:border-red-400/30 dark:bg-red-950/40 dark:text-red-200',
        'withdrawn' => 'border-slate-200 bg-slate-100 text-slate-500 dark:border-slate-600/40 dark:bg-slate-900/60 dark:text-slate-300',
        default => 'border-slate-200 bg-slate-50 text-slate-600',
    };
    $interactionTypeLabel = fn (string $type) => match ($type) {
        'follow_up' => 'Follow up',
        default => str($type)->headline(),
    };
@endphp
<div class="max-w-5xl mx-auto px-6 pt-10 pb-20 w-full">
    @if (session('success'))
        <div class="mb-6 rounded-2xl bg-neural-teal/10 px-4 py-3 text-sm text-neural-teal ring-1 ring-neural-teal/20">
            {{ session('success') }}
        </div>
    @endif

    <header class="mb-8">
        <a href="{{ route('job_pipeline.applications.index') }}" class="text-xs font-medium text-slate-brand/70 hover:text-memory-violet transition-colors">&larr; Applications</a>
        <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-3xl font-semibold tracking-tight text-deep-indigo">{{ $application->role_title }}</h1>
                <p class="mt-1.5 text-sm text-slate-brand">{{ $application->company->name }}</p>
            </div>
            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium capitalize shrink-0 {{ $stageBadgeClass }}">
                {{ str($application->stage)->headline() }}
            </span>
        </div>
    </header>

    @if ($application->jobPostingThought)
        <section class="ideatub-surface p-6 mb-10">
            <h2 class="text-sm font-semibold text-deep-indigo mb-3">Job posting</h2>
            <div class="ideatub-well whitespace-pre-wrap p-3 text-xs leading-5 text-deep-indigo">{{ $application->jobPostingThought->content }}</div>
        </section>
    @endif

    <section class="ideatub-surface p-6" x-data="{ cv: @js($application->cv_markdown ?? ''), coverLetter: @js($application->cover_letter_markdown ?? '') }">
        <h2 class="text-sm font-semibold text-deep-indigo mb-4">Documents</h2>
        <form method="POST" action="{{ route('job_pipeline.applications.update', $application) }}">
            @csrf
            @method('PATCH')

            <label for="cv_markdown" class="block text-sm font-medium text-deep-indigo mb-1.5">CV (markdown)</label>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <textarea id="cv_markdown" name="cv_markdown" x-model="cv" rows="12" class="ideatub-input w-full font-mono text-xs leading-5"></textarea>
                <div>
                    <p class="text-xs font-medium text-slate-brand/60 mb-1.5">Preview</p>
                    <pre x-text="cv" class="ideatub-well h-full min-h-[16rem] whitespace-pre-wrap p-3 text-xs leading-5 text-deep-indigo font-mono"></pre>
                </div>
            </div>

            <label for="cover_letter_markdown" class="block text-sm font-medium text-deep-indigo mt-6 mb-1.5">Cover letter (markdown)</label>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <textarea id="cover_letter_markdown" name="cover_letter_markdown" x-model="coverLetter" rows="10" class="ideatub-input w-full font-mono text-xs leading-5"></textarea>
                <div>
                    <p class="text-xs font-medium text-slate-brand/60 mb-1.5">Preview</p>
                    <pre x-text="coverLetter" class="ideatub-well h-full min-h-[13rem] whitespace-pre-wrap p-3 text-xs leading-5 text-deep-indigo font-mono"></pre>
                </div>
            </div>

            <button type="submit" class="ideatub-btn-primary mt-6 gap-2">Save draft</button>
        </form>

        <div class="mt-5 pt-5 border-t border-deep-indigo/[0.05] flex flex-wrap gap-2">
            <form method="POST" action="{{ route('job_pipeline.applications.export', [$application, 'cv']) }}">
                @csrf
                <button type="submit" class="ideatub-btn-secondary">Export CV PDF</button>
            </form>
            <form method="POST" action="{{ route('job_pipeline.applications.export', [$application, 'cover_letter']) }}">
                @csrf
                <button type="submit" class="ideatub-btn-secondary">Export cover letter PDF</button>
            </form>
            @if ($application->cv_pdf_path)
                <a href="{{ route('job_pipeline.applications.download', [$application, 'cv']) }}" class="ideatub-btn-secondary">Download CV PDF</a>
            @endif
            @if ($application->cover_letter_pdf_path)
                <a href="{{ route('job_pipeline.applications.download', [$application, 'cover_letter']) }}" class="ideatub-btn-secondary">Download cover letter PDF</a>
            @endif
        </div>
    </section>

    <section class="mt-10">
        <h2 class="text-sm font-semibold text-deep-indigo mb-3">Interactions</h2>
        @if ($application->interactions->isEmpty())
            <p class="text-sm text-slate-brand/60">No interactions logged yet.</p>
        @else
            <ul class="divide-y divide-deep-indigo/[0.05]" role="list">
                @foreach ($application->interactions as $interaction)
                    <li class="py-3 flex items-start gap-3">
                        <span class="text-xs text-slate-brand/50 tabular-nums shrink-0 w-24 pt-0.5">{{ $interaction->occurred_at->toDateString() }}</span>
                        <div class="min-w-0">
                            <span class="text-xs font-medium text-memory-violet">{{ $interactionTypeLabel($interaction->type) }}</span>
                            <p class="text-sm text-slate-brand mt-0.5">{{ $interaction->summary }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($application->outcomeThought)
        <section class="ideatub-surface p-6 mt-10">
            <h2 class="text-sm font-semibold text-deep-indigo mb-3">Outcome</h2>
            <div class="ideatub-well whitespace-pre-wrap p-3 text-xs leading-5 text-deep-indigo">{{ $application->outcomeThought->content }}</div>
        </section>
    @endif
</div>
@endsection
