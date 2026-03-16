@extends('layouts.idea')

@section('title', 'Jira — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Jira</h1>
    <p class="text-sm text-slate-brand mb-8">Connect your Jira Cloud site to sync your activity (tickets created, updated, commented) into IdeaTub. You can then search and filter by project in Stream.</p>

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
        <h2 class="text-lg font-semibold text-deep-indigo mb-4">{{ $connected ? 'Update connection' : 'Connect Jira' }}</h2>

        <form method="POST" action="{{ route('settings.jira.store') }}" class="space-y-4">
            @csrf
            <div>
                <label for="jira_site_url" class="block text-sm font-medium text-deep-indigo mb-1">Jira site URL</label>
                <input
                    type="url"
                    name="jira_site_url"
                    id="jira_site_url"
                    value="{{ old('jira_site_url', $jiraSiteUrl) }}"
                    placeholder="https://your-domain.atlassian.net"
                    class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/60 focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                    required
                />
                @error('jira_site_url')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="jira_email" class="block text-sm font-medium text-deep-indigo mb-1">Jira account email</label>
                <input
                    type="email"
                    name="jira_email"
                    id="jira_email"
                    value="{{ old('jira_email', $jiraEmail) }}"
                    placeholder="you@example.com"
                    class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/60 focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                />
                <p class="mt-1 text-[11px] text-slate-brand/70">Leave blank to use your IdeaTub email. Must match the account that owns the API token.</p>
                @error('jira_email')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="jira_api_token" class="block text-sm font-medium text-deep-indigo mb-1">API token</label>
                <input
                    type="password"
                    name="jira_api_token"
                    id="jira_api_token"
                    value="{{ old('jira_api_token') }}"
                    placeholder="{{ $connected ? '•••••••• (leave blank to keep current)' : 'Create at id.atlassian.com' }}"
                    class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/60 focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                    {{ $connected ? '' : 'required' }}
                />
                @error('jira_api_token')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <p class="text-[11px] text-slate-brand/70">Credentials are checked when you save. If they’re invalid, we’ll show an error and won’t save.</p>
            <button
                type="submit"
                class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
            >
                {{ $connected ? 'Update' : 'Connect' }}
            </button>
        </form>

        @if ($connected)
            <div class="mt-6 pt-6 border-t border-memory-violet/10 space-y-3">
                @if ($syncStatus)
                    <div id="jira-sync-status" class="rounded-lg border border-memory-violet/15 bg-white/60 px-3 py-2 text-sm text-slate-brand">
                        @if (($syncStatus['status'] ?? '') === 'running')
                            <span class="text-neural-teal font-medium">Syncing…</span> Started {{ \Carbon\Carbon::parse($syncStatus['started_at'] ?? 'now')->diffForHumans() }}. This may take a minute.
                        @elseif (($syncStatus['status'] ?? '') === 'completed')
                            <span class="text-neural-teal font-medium">Last sync:</span> {{ $syncStatus['completed_at'] ? \Carbon\Carbon::parse($syncStatus['completed_at'])->diffForHumans() : '—' }}
                            @if (!empty($syncStatus['message']))
                                — {{ $syncStatus['message'] }}
                            @endif
                        @elseif (($syncStatus['status'] ?? '') === 'failed')
                            <span class="text-red-600 font-medium">Sync failed</span>
                            @if (!empty($syncStatus['message']))
                                — {{ $syncStatus['message'] }}
                            @endif
                        @endif
                    </div>
                @endif
                <div class="flex flex-wrap gap-3">
                <form method="POST" action="{{ route('settings.jira.sync') }}" class="inline">
                    @csrf
                    <button type="submit" class="text-xs font-medium text-neural-teal hover:underline">Sync Jira now</button>
                </form>
                <form method="POST" action="{{ route('settings.jira.destroy') }}" class="inline" onsubmit="return confirm('Disconnect Jira? You can reconnect later.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs font-medium text-red-600 hover:underline">Disconnect</button>
                </form>
                </div>
            </div>
        @endif
    </div>
</div>

@if ($connected && ($syncStatus['status'] ?? '') === 'running')
<script>
(function () {
    const el = document.getElementById('jira-sync-status');
    if (!el) return;
    const url = '{{ route('settings.jira.status') }}';
    const interval = setInterval(function () {
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.status === 'completed' || d.status === 'failed') {
                    clearInterval(interval);
                    window.location.reload();
                }
            })
            .catch(function () {});
    }, 3000);
})();
</script>
@endif
@endsection
