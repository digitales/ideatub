@extends('layouts.idea')

@section('title', 'Inbound email — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Inbound email</h1>
    <p class="text-sm text-slate-brand mb-8">Emails you send from any of the addresses below will become thoughts. Send to your capture address from your email client or Fastmail.</p>

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

    @if (!empty($captureAddress))
        <div class="mb-6 rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-4 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-neural-teal/80 mb-1">Your capture address</p>
            <code class="text-sm font-mono text-deep-indigo break-all">{{ $captureAddress }}</code>
        </div>
    @else
        <p class="text-xs text-slate-brand/80 mb-6">Configure <code class="bg-white/80 px-1 rounded">POSTMARK_INBOUND_CAPTURE_ADDRESS</code> in your environment to show your capture address here.</p>
    @endif

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <h2 class="text-lg font-semibold text-deep-indigo mb-4">Allowed sender addresses</h2>
        <p class="text-sm text-slate-brand mb-4">Emails sent from these addresses will create thoughts in your account.</p>

        <div class="mb-4 rounded-xl border border-memory-violet/10 bg-white/60 px-4 py-3">
            <span class="text-sm font-medium text-deep-indigo">Primary account email</span>
            <p class="text-sm text-slate-brand mt-0.5">{{ $primaryEmail ?: '—' }}</p>
            <p class="text-[11px] text-slate-brand/70">Always allowed for capture.</p>
        </div>

        @if ($inboundAddresses->isNotEmpty())
            <ul class="space-y-4 mb-4">
                @foreach ($inboundAddresses as $address)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-memory-violet/10 bg-white/60 px-4 py-3">
                        <span class="text-sm font-mono text-deep-indigo">{{ $address->email }}</span>
                        <form method="POST" action="{{ route('settings.inbound-emails.destroy', $address) }}" class="inline" onsubmit="return confirm('Remove this address from capture?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 hover:underline">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('settings.inbound-emails.store') }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <div class="min-w-[200px] flex-1">
                <label for="email" class="sr-only">Add another email</label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    value="{{ old('email') }}"
                    placeholder="Add another email"
                    class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/60 focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                />
                @error('email')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <button
                type="submit"
                class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
            >
                Add address
            </button>
        </form>
    </div>
</div>
@endsection
