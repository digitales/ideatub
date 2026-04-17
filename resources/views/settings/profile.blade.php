@extends('layouts.idea')

@section('title', 'Profile — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Profile</h1>
    <p class="text-sm text-slate-brand mb-8">Manage your account details and session-based demo mode from one place.</p>

    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <h2 class="text-lg font-semibold text-deep-indigo mb-4">Account</h2>

        <form method="POST" action="{{ route('settings.profile.update') }}">
            @csrf
            @method('PUT')

            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Name</label>
                    <input
                        type="text"
                        name="name"
                        id="name"
                        value="{{ old('name', $user->name) }}"
                        required
                        class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
                    />
                    @error('name')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Email</label>
                    <input
                        type="email"
                        name="email"
                        id="email"
                        value="{{ old('email', $user->email) }}"
                        required
                        class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
                    />
                    @error('email')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-6">
                <button
                    type="submit"
                    class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                    style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                >
                    Save profile
                </button>
            </div>
        </form>
    </div>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <h2 class="text-lg font-semibold text-deep-indigo mb-2">Demo mode</h2>
        <p class="text-sm text-slate-brand mb-4">Demo mode is session-based. It obfuscates narrative content for this browser session without changing stored data.</p>

        @if (config('services.demo_mode.enabled'))
            <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">
                Status: {{ ! empty($demoModeEnabled) ? 'Enabled' : 'Disabled' }}
            </p>

            @if (! empty($demoModeEnabled))
                <form method="POST" action="{{ route('demo-mode.disable') }}">
                    @csrf
                    <button
                        type="submit"
                        class="text-xs font-medium text-slate-brand hover:text-memory-violet px-3 py-2 rounded-lg border border-memory-violet/15 hover:bg-memory-violet/8 transition-colors"
                    >
                        Exit demo mode
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('demo-mode.enable') }}">
                    @csrf
                    <button
                        type="submit"
                        class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                        style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                    >
                        Enable demo mode
                    </button>
                </form>
            @endif
        @else
            <p class="text-sm text-slate-brand">Demo mode is currently unavailable.</p>
        @endif
    </div>

    <div class="rounded-2xl border border-memory-violet/15 bg-white/60 p-4">
        <h2 class="text-sm font-semibold text-deep-indigo mb-3">More settings</h2>
        <div class="flex flex-col gap-2 text-sm">
            <a href="{{ route('settings.skills.index') }}" class="text-slate-brand hover:text-memory-violet">Skills</a>
            <a href="{{ route('settings.mcp-keys.index') }}" class="text-slate-brand hover:text-memory-violet">MCP key</a>
            <a href="{{ route('settings.inbound-emails.index') }}" class="text-slate-brand hover:text-memory-violet">Inbound email</a>
            <a href="{{ route('settings.ideas-revisit.index') }}" class="text-slate-brand hover:text-memory-violet">Ideas to revisit settings</a>
            @if(config('services.mail_sync.enabled', true))
                <a href="{{ route('settings.email-accounts.index') }}" class="text-slate-brand hover:text-memory-violet">Email Accounts</a>
            @endif
            @if(config('services.email_sender_policy.enabled'))
                <a href="{{ route('settings.email-sender-rules.index') }}" class="text-slate-brand hover:text-memory-violet">Email Sender Rules</a>
            @endif
            @if(config('services.jira.enabled', true))
                <a href="{{ route('settings.jira.index') }}" class="text-slate-brand hover:text-memory-violet">Jira</a>
            @endif
        </div>
    </div>
</div>
@endsection
