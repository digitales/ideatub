@auth
    @php
        $user = auth()->user();
        $operationsRemaining = $user->operationsRemainingToday();
    @endphp
    <div class="mb-6 p-4 bg-indigo-50 rounded-lg border border-indigo-200" x-data="{ remaining: {{ $operationsRemaining }} }" @operations-updated.window="remaining = $event.detail">
        @if($operationsRemaining < 0)
            <p class="text-sm font-medium text-indigo-900">
                ✓ Unlimited operations (Pro/Lifetime)
            </p>
        @else
            <p class="text-sm font-medium text-indigo-900">
                Operations remaining today: <span x-text="remaining" class="font-bold"></span> / 3
            </p>
            @if($operationsRemaining === 0)
                <p class="text-xs text-indigo-700 mt-1">
                    You've reached your daily limit. <a href="{{ route('pricing') }}" class="underline">Upgrade to Pro</a> for unlimited access.
                </p>
            @endif
        @endif
    </div>
@endauth
