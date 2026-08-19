@extends('layouts.idea')

@section('title', 'Job Pipeline — IdeaTub')

@section('content')
@php
    $stageBadgeClass = fn (string $stage) => match ($stage) {
        'researching' => 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-600/40 dark:bg-slate-900/60 dark:text-slate-200',
        'applied' => 'border-memory-violet/20 bg-memory-violet/10 text-memory-violet',
        'screening' => 'border-amber-300/70 bg-amber-50 text-amber-900 dark:border-amber-400/30 dark:bg-amber-950/40 dark:text-amber-100',
        'interviewing' => 'border-sky-300/70 bg-sky-50 text-sky-900 dark:border-sky-400/30 dark:bg-sky-950/40 dark:text-sky-100',
        'offer' => 'border-neural-teal/30 bg-neural-teal/15 text-neural-teal',
        'rejected' => 'border-red-300/70 bg-red-50 text-red-800 dark:border-red-400/30 dark:bg-red-950/40 dark:text-red-200',
        'withdrawn' => 'border-slate-200 bg-slate-100 text-slate-500 dark:border-slate-600/40 dark:bg-slate-900/60 dark:text-slate-300',
        default => 'border-slate-200 bg-slate-50 text-slate-600',
    };
    $totalApplications = $applicationsByStage->flatten()->count();
@endphp
<div class="max-w-7xl mx-auto px-6 pt-10 pb-20 w-full">
    @if (session('success'))
        <div class="mb-6 rounded-2xl bg-neural-teal/10 px-4 py-3 text-sm text-neural-teal ring-1 ring-neural-teal/20">
            {{ session('success') }}
        </div>
    @endif

    <header class="mb-8">
        <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90 mb-2">Job pipeline</p>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-3xl font-semibold tracking-tight text-deep-indigo">Applications</h1>
                <p class="mt-1.5 text-sm text-slate-brand max-w-[48ch]">
                    {{ $totalApplications === 1 ? '1 application' : $totalApplications.' applications' }} across the pipeline, grouped by stage.
                </p>
            </div>
            <div class="flex shrink-0 gap-2">
                <a href="{{ route('job_pipeline.prospects.index') }}" class="ideatub-btn-secondary">Prospects</a>
                <a href="{{ route('job_pipeline.achievements.index') }}" class="ideatub-btn-secondary">Achievements</a>
            </div>
        </div>
    </header>

    @if ($totalApplications === 0)
        <div class="ideatub-surface-muted px-6 py-12 text-center">
            <p class="text-sm text-slate-brand/70 max-w-sm mx-auto">No applications yet. Promote a prospect to start tracking one here.</p>
            <a href="{{ route('job_pipeline.prospects.index') }}" class="ideatub-btn-primary mt-5 gap-2">View prospects</a>
        </div>
    @else
        <div class="-mx-6 -my-2 overflow-x-auto px-6 py-2">
            <div class="flex gap-4" role="list">
                @foreach (\App\Models\Application::STAGES as $stage)
                    @php $stageApplications = $applicationsByStage->get($stage, collect()); @endphp
                    <div class="w-64 shrink-0" role="listitem">
                        <div class="flex items-center justify-between gap-2 border-t-2 border-deep-indigo/[0.06] pt-3 mb-3">
                            <h2 class="text-sm font-medium text-deep-indigo">{{ str($stage)->headline() }}</h2>
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium tabular-nums {{ $stageBadgeClass($stage) }}">
                                {{ $stageApplications->count() }}
                            </span>
                        </div>
                        <div class="space-y-2">
                            @forelse ($stageApplications as $application)
                                <a href="{{ route('job_pipeline.applications.show', $application) }}" class="ideatub-surface group block p-4 transition hover:ring-memory-violet/25 dark:hover:ring-violet-400/30">
                                    <div class="font-medium text-deep-indigo group-hover:text-memory-violet transition-colors line-clamp-2">{{ $application->role_title }}</div>
                                    <div class="mt-1 text-sm text-slate-brand/70 line-clamp-1">{{ $application->company->name }}</div>
                                    @if ($application->last_activity_at)
                                        <div class="mt-2.5 pt-2.5 border-t border-deep-indigo/[0.05] text-xs text-slate-brand/50">
                                            Updated {{ $application->last_activity_at->diffForHumans() }}
                                        </div>
                                    @endif
                                </a>
                            @empty
                                <p class="text-xs text-slate-brand/40 px-1 py-2">No applications</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
