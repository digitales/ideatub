@php
    $inboxBody = $item->body ?? '';
    if (($item->generator_type ?? '') === 'weekly_revisit') {
        $inboxBody = \App\Support\Inbox\WeeklyRevisitBodyFormatter::sanitizeStoredBody($inboxBody);
    }
    $nested = $nested ?? false;
@endphp

<article
    data-inbox-item-id="{{ $item->id }}"
    @class([
        'rounded-2xl border border-memory-violet/20 bg-white/90 shadow-[0_4px_24px_rgba(109,106,247,0.08)]',
        'p-5' => ! $nested,
        'border-dashed p-4' => $nested,
    ])
>
    <div class="flex items-start justify-between gap-4">
        <div>
            @if (! $nested)
                <p class="text-[11px] font-semibold uppercase tracking-[0.1em] text-memory-violet/80">{{ str_replace('_', ' ', $item->generator_type) }}</p>
            @endif
            <h2 @class([
                'font-semibold text-deep-indigo',
                'mt-1 text-lg' => ! $nested,
                'text-base' => $nested,
            ])>{{ $item->title }}</h2>
        </div>
        <p class="shrink-0 text-xs text-slate-brand/60">{{ $item->generated_at?->diffForHumans() }}</p>
    </div>

    <div class="prose-memory-list-headings prose prose-sm mt-3 max-w-none text-slate-brand prose-headings:text-deep-indigo prose-p:text-slate-brand prose-strong:text-deep-indigo prose-li:text-slate-brand">
        <x-safe-markdown :markdown="$inboxBody" />
    </div>

    @if (($item->generator_type ?? '') === 'email_sender_review')
        <div class="mt-4 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('inbox.email-review.action', $item) }}" @submit.prevent="submitAction($event)">
                @csrf
                <input type="hidden" name="action" value="allow">
                <button type="submit" data-idle-label="Allow sender" data-pending-label="Allowing sender..." class="rounded-lg bg-neural-teal px-3 py-1.5 text-xs font-medium text-white">Allow sender</button>
            </form>

            <form method="POST" action="{{ route('inbox.email-review.action', $item) }}" @submit.prevent="submitAction($event)">
                @csrf
                <input type="hidden" name="action" value="ignore">
                <button type="submit" data-idle-label="Ignore sender" data-pending-label="Ignoring sender..." class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-brand">Ignore sender</button>
            </form>

            <form method="POST" action="{{ route('inbox.email-review.action', $item) }}" @submit.prevent="submitAction($event)">
                @csrf
                <input type="hidden" name="action" value="extra_process">
                <button type="submit" data-idle-label="Extra process sender" data-pending-label="Extra processing sender..." class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet">Extra process sender</button>
            </form>

            <form method="POST" action="{{ route('inbox.email-review.action', $item) }}" @submit.prevent="submitAction($event)">
                @csrf
                <input type="hidden" name="action" value="save_thought">
                <button type="submit" data-idle-label="Save as thought" data-pending-label="Saving as thought..." class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet">Save as thought</button>
            </form>
        </div>
    @elseif (($item->generator_type ?? '') === 'import_completed')
        <div class="mt-4 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('inbox.done', $item) }}" @submit.prevent="submitAction($event)">
                @csrf
                <button type="submit" data-idle-label="OK" data-pending-label="Dismissing..." class="rounded-lg bg-neural-teal px-3 py-1.5 text-xs font-medium text-white">OK</button>
            </form>
        </div>
    @else
        <div class="mt-4 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('inbox.done', $item) }}" @submit.prevent="submitAction($event)">
                @csrf
                <button type="submit" data-idle-label="Done" data-pending-label="Marking done..." class="rounded-lg bg-neural-teal px-3 py-1.5 text-xs font-medium text-white">Done</button>
            </form>

            <form method="POST" action="{{ route('inbox.snooze', $item) }}" @submit.prevent="submitAction($event)">
                @csrf
                <input type="hidden" name="preset" value="tomorrow">
                <button type="submit" data-idle-label="Tomorrow" data-pending-label="Snoozing..." class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-slate-brand">Tomorrow</button>
            </form>

            <form method="POST" action="{{ route('inbox.snooze', $item) }}" @submit.prevent="submitAction($event)">
                @csrf
                <input type="hidden" name="preset" value="next_week">
                <button type="submit" data-idle-label="Next week" data-pending-label="Snoozing..." class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-slate-brand">Next week</button>
            </form>

            <form method="POST" action="{{ route('inbox.save-thought', $item) }}" @submit.prevent="submitAction($event)">
                @csrf
                <button type="submit" data-idle-label="Save as thought" data-pending-label="Saving as thought..." class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet">Save as thought</button>
            </form>
        </div>
    @endif
</article>
