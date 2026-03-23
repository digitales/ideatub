@php
    $actionLabels = [
        \App\Models\EmailSenderRule::ACTION_ALLOW => 'Allow',
        \App\Models\EmailSenderRule::ACTION_IGNORE => 'Ignore',
        \App\Models\EmailSenderRule::ACTION_REVIEW => 'Review',
        \App\Models\EmailSenderRule::ACTION_EXTRA_PROCESS => 'Extra process',
    ];

    $rule = $senderRuleContext['rule'] ?? null;
    $sender = $senderRuleContext['normalized_sender'] ?? null;
    $senderAvailable = $senderRuleContext['sender_available'] ?? false;
    $selectedAction = old('action', $rule?->action ?? \App\Models\EmailSenderRule::ACTION_REVIEW);
@endphp

@if ($senderRuleContext['enabled'] ?? false)
    <div class="mt-5 rounded-xl border border-memory-violet/10 bg-white/60 p-4">
        <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80">Sender rule</p>

        @if ($senderAvailable)
            <p class="mt-3 text-sm font-mono text-deep-indigo break-all">{{ $sender }}</p>

            @if ($rule)
                <p class="mt-3 text-sm text-slate-brand">
                    <span class="font-medium text-deep-indigo">Current rule:</span>
                    {{ $actionLabels[$rule->action] ?? $rule->action }}
                </p>
            @endif

            @if (! $rule || $rule->action !== \App\Models\EmailSenderRule::ACTION_ALLOW)
                <form method="POST" action="{{ route('thoughts.sender-rules.store', $thought) }}" class="mt-3">
                    @csrf
                    <input type="hidden" name="action" value="{{ \App\Models\EmailSenderRule::ACTION_ALLOW }}">
                    <button
                        type="submit"
                        class="rounded-lg px-3 py-2 text-xs font-medium text-white"
                        style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                    >
                        Whitelist sender
                    </button>
                </form>
            @endif

            @if ($rule && $rule->action === \App\Models\EmailSenderRule::ACTION_ALLOW)
                <form method="POST" action="{{ route('thoughts.sender-rules.destroy', $thought) }}" class="mt-3">
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        class="rounded-lg border border-memory-violet/20 px-3 py-2 text-xs font-medium text-deep-indigo"
                    >
                        Remove from whitelist
                    </button>
                </form>
            @endif

            <form method="POST" action="{{ route('thoughts.sender-rules.store', $thought) }}" class="mt-4 space-y-3">
                @csrf

                <label class="block text-xs font-medium text-deep-indigo" for="sender-rule-action-{{ $thought->id }}">
                    Rule action
                </label>
                <select
                    id="sender-rule-action-{{ $thought->id }}"
                    name="action"
                    class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
                >
                    @foreach ($actionLabels as $action => $label)
                        <option value="{{ $action }}" @selected($selectedAction === $action)>{{ $label }}</option>
                    @endforeach
                </select>

                @error('action')
                    <p class="text-xs text-rose-600">{{ $message }}</p>
                @enderror

                <button
                    type="submit"
                    class="rounded-lg border border-memory-violet/20 px-3 py-2 text-xs font-medium text-deep-indigo"
                >
                    Save rule
                </button>
            </form>

            @if ($rule && $rule->action !== \App\Models\EmailSenderRule::ACTION_ALLOW)
                <form method="POST" action="{{ route('thoughts.sender-rules.destroy', $thought) }}" class="mt-3">
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        class="rounded-lg border border-memory-violet/20 px-3 py-2 text-xs font-medium text-slate-brand"
                    >
                        Remove rule
                    </button>
                </form>
            @endif
        @else
            <p class="mt-3 text-sm text-slate-brand">Sender rule unavailable for this email.</p>
        @endif
    </div>
@endif
