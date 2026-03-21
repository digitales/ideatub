@php
    $actionLabels = [
        \App\Models\EmailSenderRule::ACTION_ALLOW => 'Allow',
        \App\Models\EmailSenderRule::ACTION_IGNORE => 'Ignore',
        \App\Models\EmailSenderRule::ACTION_REVIEW => 'Review',
        \App\Models\EmailSenderRule::ACTION_EXTRA_PROCESS => 'Extra process',
    ];
@endphp

@extends('layouts.idea')

@section('title', 'Email sender rules — IdeaTub')

@section('content')
<div class="max-w-[720px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Email sender rules</h1>
    <p class="text-sm text-slate-brand mb-8">Exact sender addresses and how IdeaTub should treat mail from each address for Postmark inbound and Fastmail sync.</p>

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
        <h2 class="text-lg font-semibold text-deep-indigo mb-4">Your rules</h2>
        @if ($rules->isEmpty())
            <p class="text-sm text-slate-brand">No sender rules yet. Add one below.</p>
        @else
            <ul class="space-y-6">
                @foreach ($rules as $rule)
                    <li class="rounded-xl border border-memory-violet/10 bg-white/60 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-mono text-deep-indigo break-all">{{ $rule->sender_email }}</p>
                            </div>
                            <form
                                method="POST"
                                action="{{ route('settings.email-sender-rules.update', $rule) }}"
                                class="flex flex-wrap items-end gap-2"
                            >
                                @csrf
                                @method('PATCH')
                                <div>
                                    <label for="action-{{ $rule->id }}" class="sr-only">Action</label>
                                    <select
                                        name="action"
                                        id="action-{{ $rule->id }}"
                                        class="rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                                    >
                                        @foreach ($actionLabels as $value => $label)
                                            <option value="{{ $value }}" @selected(old('action', $rule->action) === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button
                                    type="submit"
                                    class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                                    style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                                >
                                    Update
                                </button>
                            </form>
                            <form
                                method="POST"
                                action="{{ route('settings.email-sender-rules.destroy', $rule) }}"
                                class="inline"
                                onsubmit="return confirm('Remove this sender rule?');"
                            >
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 hover:underline">Remove</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <h2 class="text-lg font-semibold text-deep-indigo mb-4">Add a rule</h2>
        <form method="POST" action="{{ route('settings.email-sender-rules.store') }}" class="space-y-4">
            @csrf
            <div>
                <label for="sender_email" class="block text-sm font-medium text-deep-indigo mb-1">Sender email</label>
                <input
                    type="email"
                    name="sender_email"
                    id="sender_email"
                    value="{{ old('sender_email') }}"
                    placeholder="newsletter@example.com"
                    class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/60 focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                />
                @error('sender_email')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="action" class="block text-sm font-medium text-deep-indigo mb-1">Action</label>
                <select
                    name="action"
                    id="action"
                    class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                >
                    @foreach ($actionLabels as $value => $label)
                        <option value="{{ $value }}" @selected(old('action', \App\Models\EmailSenderRule::ACTION_REVIEW) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('action')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <button
                type="submit"
                class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
            >
                Add rule
            </button>
        </form>
    </div>
</div>
@endsection
