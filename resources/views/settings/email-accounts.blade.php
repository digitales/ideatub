@extends('layouts.idea')

@section('title', 'Email Accounts — IdeaTub')

@section('content')
<div class="max-w-[720px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Email Accounts</h1>
    <p class="text-sm text-slate-brand mb-8">Connect a Fastmail account to import sent and directly addressed personal email into IdeaTub.</p>

    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <h2 class="text-lg font-semibold text-deep-indigo mb-4">Fastmail</h2>
        <p class="text-sm text-slate-brand mb-4">Connect your Fastmail mailbox with an API token so IdeaTub can backfill history and sync new mail.</p>
        <p class="text-[11px] text-slate-brand/70 mb-6">Synced email content is sent through the configured AI pipeline for summaries and metadata extraction.</p>

        <form method="POST" action="{{ route('settings.email-accounts.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="provider" value="fastmail" />

            <div>
                <label for="display_name" class="block text-sm font-medium text-deep-indigo mb-1">Display name</label>
                <input
                    type="text"
                    name="display_name"
                    id="display_name"
                    value="{{ old('display_name') }}"
                    placeholder="Primary Fastmail"
                    class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/60 focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                />
                @error('display_name')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="account_email" class="block text-sm font-medium text-deep-indigo mb-1">Fastmail address</label>
                <input
                    type="email"
                    name="account_email"
                    id="account_email"
                    value="{{ old('account_email') }}"
                    placeholder="you@fastmail.fm"
                    class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/60 focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                />
                @error('account_email')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="credential" class="block text-sm font-medium text-deep-indigo mb-1">API token</label>
                <input
                    type="password"
                    name="credential"
                    id="credential"
                    class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/60 focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                />
                @error('credential')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="initial_backfill_window_days" class="block text-sm font-medium text-deep-indigo mb-1">Initial backfill window</label>
                <select
                    name="initial_backfill_window_days"
                    id="initial_backfill_window_days"
                    class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                >
                    <option value="30" @selected(old('initial_backfill_window_days') === '30')>30 days</option>
                    <option value="90" @selected(old('initial_backfill_window_days', '90') === '90')>90 days</option>
                    <option value="365" @selected(old('initial_backfill_window_days') === '365')>365 days</option>
                </select>
                @error('initial_backfill_window_days')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-wrap gap-4 text-sm text-deep-indigo">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="sync_enabled" value="1" @checked(old('sync_enabled', '1') === '1')>
                    <span>Enable sync</span>
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="include_sent" value="1" @checked(old('include_sent', '1') === '1')>
                    <span>Include sent</span>
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="include_received_personal" value="1" @checked(old('include_received_personal', '1') === '1')>
                    <span>Include received personal mail</span>
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="exclude_bulk" value="1" @checked(old('exclude_bulk', '1') === '1')>
                    <span>Exclude bulk mail</span>
                </label>
            </div>

            <button
                type="submit"
                class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
            >
                Connect Fastmail
            </button>
        </form>
    </div>

    @foreach ($mailAccounts as $mailAccountCard)
        <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-deep-indigo">{{ $mailAccountCard->displayName() }}</h3>
                    <p class="text-sm text-slate-brand">{{ $mailAccountCard->accountEmail() }}</p>
                    @if ($mailAccountCard->hasLatestSyncRun())
                        <p class="mt-1 text-xs text-slate-brand/80">
                            Latest sync: {{ $mailAccountCard->latestSyncStatus() }}
                            @if ($mailAccountCard->lastSyncedHumanText())
                                &mdash; {{ $mailAccountCard->lastSyncedHumanText() }}
                            @endif
                        </p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-3 text-xs font-medium">
                    <form method="POST" action="{{ route('settings.email-accounts.backfill', $mailAccountCard->mailAccount()) }}">
                        @csrf
                        <button type="submit" class="text-neural-teal hover:underline">Run backfill</button>
                    </form>
                    <form method="POST" action="{{ route('settings.email-accounts.sync', $mailAccountCard->mailAccount()) }}">
                        @csrf
                        <button type="submit" class="text-neural-teal hover:underline">Sync now</button>
                    </form>
                    <form method="POST" action="{{ route('settings.email-accounts.destroy', $mailAccountCard->mailAccount()) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-600 hover:underline">Disconnect</button>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
