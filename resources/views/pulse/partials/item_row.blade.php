@php
    $severity = $item->severity ?? null;
    $severityBadgeClass = match ($severity) {
        'high' => 'border-red-300/70 bg-red-50 text-red-800 dark:border-red-400/30 dark:bg-red-950/40 dark:text-red-200',
        'medium' => 'border-amber-300/70 bg-amber-50 text-amber-900 dark:border-amber-400/30 dark:bg-amber-950/40 dark:text-amber-100',
        'low' => 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-600/40 dark:bg-slate-900/60 dark:text-slate-200',
        default => null,
    };

    $kindLabel = match ($item->kind) {
        'memory_health' => 'Memory health',
        'tag_memory_summary' => 'Tag memory',
        'meeting_action' => 'Meeting action',
        'wm_next_action' => 'Next action',
        'wm_open_question' => 'Open question',
        'jira_issue' => 'Jira issue',
        'jira_follow_up' => 'Jira follow-up',
        default => \Illuminate\Support\Str::headline(str_replace('_', ' ', $item->kind)),
    };

    $subtitleParts = ($item->subtitle !== null && $item->subtitle !== '')
        ? explode(' — ', $item->subtitle, 2)
        : [];
    $subtitleLead = $subtitleParts[0] ?? null;
    $subtitleDetail = $subtitleParts[1] ?? null;
    $isExternalLink = str_starts_with($item->href, 'http://') || str_starts_with($item->href, 'https://');
@endphp

<li class="px-5 py-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                @if ($severity !== null && $severityBadgeClass !== null)
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize {{ $severityBadgeClass }}">
                        {{ $severity }}
                    </span>
                @endif
                <span class="text-xs font-medium text-memory-violet/80">{{ $kindLabel }}</span>
            </div>

            <a
                href="{{ $item->href }}"
                @if ($isExternalLink) target="_blank" rel="noopener noreferrer" @endif
                class="group mt-2 inline-flex max-w-full items-start gap-1.5 text-base font-medium text-deep-indigo transition-colors hover:text-memory-violet focus:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/40 focus-visible:ring-offset-2"
            >
                <span class="min-w-0 text-pretty">{{ $item->title }}</span>
                @if ($isExternalLink)
                    <svg class="mt-1 h-3.5 w-3.5 shrink-0 text-slate-brand/50 group-hover:text-memory-violet/70" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                    <span class="sr-only">(opens in new tab)</span>
                @endif
            </a>

            @if ($subtitleLead !== null)
                <p class="mt-1.5 text-sm font-medium text-slate-brand">{{ $subtitleLead }}</p>
            @endif
            @if ($subtitleDetail !== null)
                <p class="mt-0.5 max-w-[65ch] text-pretty text-sm text-slate-brand/80">{{ $subtitleDetail }}</p>
            @endif
        </div>

        @if ($item->commitmentId !== null)
            <div class="flex shrink-0 flex-wrap items-center gap-2 sm:pt-1">
                <form method="POST" action="{{ route('commitments.done', $item->commitmentId) }}">
                    @csrf
                    <button
                        type="submit"
                        class="rounded-lg bg-neural-teal px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-neural-teal/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-neural-teal/40 focus-visible:ring-offset-2"
                    >
                        Done
                    </button>
                </form>
                <form method="POST" action="{{ route('commitments.snooze', $item->commitmentId) }}">
                    @csrf
                    <input type="hidden" name="preset" value="tomorrow">
                    <button
                        type="submit"
                        class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-slate-brand transition-colors hover:bg-memory-violet/5 hover:text-deep-indigo focus:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/30 focus-visible:ring-offset-2"
                    >
                        Snooze
                    </button>
                </form>
            </div>
        @endif
    </div>
</li>
