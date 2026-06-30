@extends('layouts.idea')

@section('title', 'Inbox — IdeaTub')

@section('content')
<div
    class="max-w-4xl mx-auto px-6 pt-16 pb-24"
    x-data="inboxPage()"
    data-inbox-initial-count="{{ $inboxInitialCount ?? 0 }}"
>
    <div data-inbox-flash-region class="pointer-events-none fixed inset-x-0 top-20 z-20 px-6">
        <div class="mx-auto max-w-4xl space-y-3">
            @if (session('success'))
                <div class="pointer-events-auto rounded-xl border border-neural-teal/25 bg-neural-teal/10 px-4 py-3 text-sm text-neural-teal shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="pointer-events-auto rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
                    {{ session('error') }}
                </div>
            @endif

            <div x-show="flashSuccess" x-cloak class="pointer-events-auto rounded-xl border border-neural-teal/25 bg-neural-teal/10 px-4 py-3 text-sm text-neural-teal shadow-[0_4px_24px_rgba(109,106,247,0.08)]" x-text="flashSuccess"></div>
            <div x-show="flashError" x-cloak class="pointer-events-auto rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600 shadow-[0_4px_24px_rgba(109,106,247,0.08)]" x-text="flashError"></div>
        </div>
    </div>

    <div
        x-show="confirmOpen"
        x-cloak
        class="fixed inset-0 z-30 flex items-center justify-center bg-deep-indigo/20 px-6"
        role="dialog"
        aria-modal="true"
        aria-labelledby="inbox-group-confirm-title"
    >
        <div class="w-full max-w-md rounded-2xl border border-memory-violet/20 bg-white p-6 shadow-[0_4px_24px_rgba(109,106,247,0.12)]">
            <h2 id="inbox-group-confirm-title" class="text-lg font-semibold text-deep-indigo">Confirm bulk action</h2>
            <p class="mt-2 text-sm text-slate-brand" x-text="confirmMessage"></p>
            <div class="mt-6 flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-slate-brand"
                    @click="closeGroupConfirm()"
                    :disabled="groupBulkPending"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-lg bg-neural-teal px-3 py-1.5 text-xs font-medium text-white"
                    @click="confirmGroupBulk()"
                    :disabled="groupBulkPending"
                    x-text="groupBulkPending ? 'Working...' : 'Confirm'"
                ></button>
            </div>
        </div>
    </div>

    <div class="mb-8">
        <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Inbox</h1>
        <p class="mt-2 text-sm text-slate-brand">Agent-generated prompts that need triage.</p>
        @if (config('services.email_sender_policy.enabled'))
            <a href="{{ route('settings.email-sender-rules.index') }}" class="mt-2 inline-block text-xs text-neural-teal hover:underline">Manage sender rules →</a>
        @endif
    </div>

    @if ($groups->isEmpty() && $singles->isEmpty())
        <div class="rounded-2xl border border-memory-violet/20 bg-white/80 p-8 text-sm text-slate-brand shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            No inbox items right now.
        </div>
    @else
        @if ($groups->isNotEmpty())
            <div class="mb-6 space-y-4">
                @foreach ($groups as $group)
                    @include('inbox.partials.group', ['group' => $group])
                @endforeach
            </div>
        @endif

        @if ($singles->isNotEmpty())
            <div class="space-y-4">
                @foreach ($singles as $item)
                    @include('inbox.partials.item', ['item' => $item])
                @endforeach
            </div>

            <div class="mt-8">
                {{ $singles->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
