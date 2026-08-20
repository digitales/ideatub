@extends('layouts.idea')

@section('title', 'Prospects — IdeaTub')

@section('content')
@php
    $sourceBadgeClass = fn (string $source) => match ($source) {
        'linkedin' => 'border-sky-300/70 bg-sky-50 text-sky-900 dark:border-sky-400/30 dark:bg-sky-950/40 dark:text-sky-100',
        'referral' => 'border-neural-teal/30 bg-neural-teal/15 text-neural-teal',
        'direct' => 'border-memory-violet/20 bg-memory-violet/10 text-memory-violet',
        default => 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-600/40 dark:bg-slate-900/60 dark:text-slate-200',
    };
    $fitLabel = fn (?int $score) => match (true) {
        $score === null => 'Not scored',
        $score >= 75 => 'Strong Fit',
        $score >= 60 => 'Good Fit',
        $score >= 45 => 'Moderate Fit',
        $score >= 30 => 'Weak Fit',
        default => 'Poor Fit',
    };
    $fitBadgeClass = fn (?int $score) => match (true) {
        $score === null => 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-600/40 dark:bg-slate-900/60 dark:text-slate-200',
        $score >= 75 => 'border-emerald-300/70 bg-emerald-50 text-emerald-900 dark:border-emerald-400/30 dark:bg-emerald-950/40 dark:text-emerald-100',
        $score >= 60 => 'border-sky-300/70 bg-sky-50 text-sky-900 dark:border-sky-400/30 dark:bg-sky-950/40 dark:text-sky-100',
        $score >= 45 => 'border-amber-300/70 bg-amber-50 text-amber-900 dark:border-amber-400/30 dark:bg-amber-950/40 dark:text-amber-100',
        $score >= 30 => 'border-orange-300/70 bg-orange-50 text-orange-900 dark:border-orange-400/30 dark:bg-orange-950/40 dark:text-orange-100',
        default => 'border-rose-300/70 bg-rose-50 text-rose-900 dark:border-rose-400/30 dark:bg-rose-950/40 dark:text-rose-100',
    };
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
                <h1 class="text-3xl font-semibold tracking-tight text-deep-indigo">Prospects</h1>
                <p class="mt-1.5 text-sm text-slate-brand max-w-[48ch]">
                    {{ $prospects->count() === 1 ? '1 role' : $prospects->count().' roles' }} sourced and not yet decided on.
                </p>
            </div>
            <a href="{{ route('job_pipeline.applications.index') }}" class="ideatub-btn-secondary shrink-0">Applications</a>
        </div>
    </header>

    @if ($prospects->isEmpty())
        <div class="ideatub-surface-muted px-6 py-12 text-center">
            <p class="text-sm text-slate-brand/70 max-w-sm mx-auto">No open prospects. Add one via the <code class="text-xs">add_prospect</code> MCP tool, or from your sourcing workflow.</p>
        </div>
    @else
        <div
            class="relative"
            x-data="{
                canScrollRight: false,
                checkScroll() {
                    const el = this.$refs.prospectsScroll;
                    this.canScrollRight = el.scrollWidth - el.scrollLeft - el.clientWidth > 4;
                },
            }"
            x-init="checkScroll(); window.addEventListener('resize', checkScroll)"
        >
            <div
                x-ref="prospectsScroll"
                @scroll="checkScroll()"
                class="ideatub-scroll-x -mx-6 -my-2 overflow-x-auto whitespace-nowrap px-6 py-2"
            >
            <div class="inline-block min-w-full align-middle">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-brand/60">
                            <th class="pb-2 pr-4 font-medium whitespace-nowrap">Company</th>
                            <th class="pb-2 pr-4 font-medium whitespace-nowrap">Role</th>
                            <th class="pb-2 pr-4 font-medium whitespace-nowrap">Source</th>
                            <th class="pb-2 pr-4 font-medium whitespace-nowrap">Fit</th>
                            <th class="pb-2 pr-4 font-medium whitespace-nowrap">Notes</th>
                            <th class="pb-2 font-medium whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-deep-indigo/[0.05]">
                        @foreach ($prospects as $prospect)
                            <tr
                                x-data="{
                                    notes: @js($prospect->notes ?? ''),
                                    expanded: false,
                                    saving: false,
                                    error: '',
                                    async save() {
                                        if (this.saving) return;
                                        this.saving = true;
                                        this.error = '';
                                        try {
                                            const res = await fetch('{{ route('job_pipeline.prospects.update', $prospect) }}', {
                                                method: 'PATCH',
                                                headers: {
                                                    'Content-Type': 'application/json',
                                                    Accept: 'application/json',
                                                    'X-Requested-With': 'XMLHttpRequest',
                                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '',
                                                },
                                                body: JSON.stringify({ notes: this.notes }),
                                            });
                                            if (!res.ok) {
                                                this.error = 'Failed to save notes.';
                                            }
                                        } catch {
                                            this.error = 'Network error. Try again.';
                                        } finally {
                                            this.saving = false;
                                        }
                                    },
                                }"
                            >
                                <td class="py-3 pr-4 align-top font-medium text-deep-indigo whitespace-nowrap">{{ $prospect->company }}</td>
                                <td class="py-3 pr-4 align-top text-slate-brand">
                                    @if ($prospect->url)
                                        <a href="{{ $prospect->url }}" target="_blank" rel="noopener noreferrer" title="{{ $prospect->role_title }}" class="group inline-flex max-w-[16rem] items-center gap-1 hover:text-memory-violet transition-colors">
                                            <span class="min-w-0 truncate">{{ $prospect->role_title }}</span>
                                            <span aria-hidden="true" class="shrink-0">↗</span>
                                        </a>
                                    @else
                                        <span title="{{ $prospect->role_title }}" class="inline-block max-w-[16rem] truncate align-top">{{ $prospect->role_title }}</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 align-top whitespace-nowrap">
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium capitalize {{ $sourceBadgeClass($prospect->source) }}">
                                        {{ str($prospect->source)->headline() }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4 align-top whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium {{ $fitBadgeClass($prospect->fit_score) }}">
                                        @if ($prospect->fit_score !== null)
                                            <span class="font-semibold tabular-nums">{{ $prospect->fit_score }}</span>
                                        @endif
                                        {{ $fitLabel($prospect->fit_score) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4 max-w-[16rem] align-top">
                                    <button
                                        type="button"
                                        x-show="!expanded"
                                        @click="expanded = true; $nextTick(() => $refs.notesInput.focus())"
                                        class="inline-block max-w-[16rem] truncate text-left text-slate-brand hover:text-deep-indigo"
                                        x-text="notes || '—'"
                                    >{{ $prospect->notes ?: '—' }}</button>
                                    <textarea
                                        x-show="expanded"
                                        x-cloak
                                        x-ref="notesInput"
                                        x-model="notes"
                                        rows="3"
                                        class="ideatub-input w-full resize-y whitespace-normal"
                                        @blur="expanded = false; save()"
                                    ></textarea>
                                    <p x-show="error" x-cloak x-text="error" class="text-[11px] text-red-600 mt-1 whitespace-normal"></p>
                                </td>
                                <td class="py-3 align-top">
                                    <div class="flex flex-nowrap items-start gap-1.5">
                                        <form method="POST" action="{{ route('job_pipeline.prospects.shortlist', $prospect) }}">@csrf<button type="submit" class="ideatub-btn-secondary-sm whitespace-nowrap">Shortlist</button></form>
                                        <form method="POST" action="{{ route('job_pipeline.prospects.mark-applied', $prospect) }}">@csrf<button type="submit" class="ideatub-btn-secondary-sm whitespace-nowrap">Mark applied</button></form>
                                        <form method="POST" action="{{ route('job_pipeline.prospects.dismiss', $prospect) }}">@csrf<button type="submit" class="ideatub-btn-secondary-sm whitespace-nowrap">Dismiss</button></form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            </div>
            <div
                x-show="canScrollRight"
                x-cloak
                aria-hidden="true"
                class="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-l from-[#eef2ff] to-transparent dark:from-gray-950"
            ></div>
        </div>
    @endif
</div>
@endsection
