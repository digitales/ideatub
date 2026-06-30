@php
    use App\Support\Inbox\InboxGroupDescriptor;
@endphp

<article
    data-inbox-group="{{ $group->generatorType }}"
    class="rounded-2xl border border-memory-violet/20 bg-white/90 p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)]"
    x-data="{ expanded: false }"
>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.1em] text-memory-violet/80">
                {{ str_replace('_', ' ', $group->generatorType) }} ({{ $group->items->count() }})
            </p>
            <h2 class="mt-1 text-lg font-semibold text-deep-indigo">{{ $group->title }}</h2>
            <p class="mt-1 text-sm text-slate-brand">{{ $group->subtitle }}</p>
        </div>
        <button
            type="button"
            class="shrink-0 rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-slate-brand hover:bg-memory-violet/5"
            @click="expanded = !expanded"
            :aria-expanded="expanded.toString()"
        >
            <span x-text="expanded ? 'Collapse' : 'Expand'"></span>
        </button>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($group->bulkActions as $bulkAction)
            @php
                $requiresConfirm = InboxGroupDescriptor::actionRequiresConfirmation($bulkAction);
                $isPrimary = in_array($bulkAction, ['done_all', 'ok_all', 'allow_all'], true);
            @endphp
            <button
                type="button"
                data-bulk-action="{{ $bulkAction }}"
                data-idle-label="{{ InboxGroupDescriptor::bulkActionLabel($bulkAction) }}"
                data-pending-label="{{ InboxGroupDescriptor::bulkActionPendingLabel($bulkAction) }}"
                @if ($requiresConfirm)
                    @click="openGroupConfirm(@js($group->generatorType), @js($bulkAction), {{ $group->items->count() }}, @js(InboxGroupDescriptor::bulkActionLabel($bulkAction)))"
                @else
                    @click="submitGroupBulk(@js($group->generatorType), @js($bulkAction), $event.currentTarget)"
                @endif
                @class([
                    'rounded-lg px-3 py-1.5 text-xs font-medium',
                    'bg-neural-teal text-white' => $isPrimary,
                    'border border-slate-300 text-slate-brand' => ! $isPrimary,
                ])
            >
                {{ InboxGroupDescriptor::bulkActionLabel($bulkAction) }}
            </button>
        @endforeach
    </div>

    <div x-show="expanded" x-cloak class="mt-4 space-y-3 border-t border-memory-violet/10 pt-4">
        @foreach ($group->items as $item)
            @include('inbox.partials.item', ['item' => $item, 'nested' => true])
        @endforeach
    </div>
</article>
