@extends('layouts.idea')

@section('title', 'Shared documents — IdeaTub')

@section('content')
<div class="max-w-[720px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Shared documents</h1>
    <p class="text-sm text-slate-brand mb-8">Share read-only links to long-form documents from your Stream (plans, specs, meetings, research, and other capture types). Each link can be password-protected and given an expiry.</p>

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

    {{-- Share another --}}
    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-8">
        <h2 class="text-lg font-semibold text-deep-indigo mb-4">Share another</h2>
        <form method="POST" action="{{ route('shared-research.store') }}" class="space-y-4">
            @csrf
            <div>
                <label for="thought_id" class="block text-sm font-medium text-deep-indigo mb-1">Document (top-level thought)</label>
                <select name="thought_id" id="thought_id" required class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo focus:border-neural-teal focus:ring-1 focus:ring-neural-teal">
                    <option value="">Choose a thought…</option>
                    @foreach ($topLevelThoughts as $t)
                        <option value="{{ $t->id }}" @selected(old('thought_id', request('create')) == $t->id)>
                            {{ Str::limit($t->content, 60) }}
                        </option>
                    @endforeach
                </select>
                @if ($topLevelThoughts->isEmpty())
                    <p class="mt-1 text-xs text-slate-brand">No top-level thoughts yet. <a href="{{ route('idea.stream') }}" class="text-neural-teal hover:underline">Open Stream</a> to create one.</p>
                @endif
                @error('thought_id')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="password" class="block text-sm font-medium text-deep-indigo mb-1">Password (optional)</label>
                    <input type="text" name="password" id="password" value="{{ old('password') }}" placeholder="Leave blank for no password" autocomplete="off" class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/60 focus:border-neural-teal focus:ring-1 focus:ring-neural-teal" />
                    @error('password')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="expires_at" class="block text-sm font-medium text-deep-indigo mb-1">Expires (optional)</label>
                    <input type="date" name="expires_at" id="expires_at" value="{{ old('expires_at') }}" min="{{ date('Y-m-d', strtotime('+1 day')) }}" class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo focus:border-neural-teal focus:ring-1 focus:ring-neural-teal" />
                    @error('expires_at')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <button type="submit" class="text-sm font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90" style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
                Create share link
            </button>
        </form>
    </div>

    {{-- List of shares --}}
    <div class="space-y-4">
        <h2 class="text-lg font-semibold text-deep-indigo">Your share links</h2>
        @forelse ($shares as $share)
            <div id="share-{{ $share->id }}" class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)] {{ (string) $focusShareId === (string) $share->id ? 'ring-2 ring-neural-teal' : '' }}">
                @if ($share->thought)
                    <p class="text-sm text-deep-indigo mb-2">{{ Str::limit($share->thought->content, 80) }}</p>
                @endif
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <a href="{{ $shareUrls[$share->id] }}" target="_blank" rel="noopener" class="text-xs font-mono text-memory-violet hover:underline bg-white/80 px-2 py-1 rounded break-all flex-1 min-w-0">{{ $shareUrls[$share->id] }}</a>
                    <button type="button" data-copy-url="{{ $shareUrls[$share->id] }}" class="copy-url-btn text-xs font-medium text-white px-3 py-1.5 rounded-lg transition-opacity hover:opacity-90 flex-shrink-0" style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
                        Copy
                    </button>
                </div>
                <div class="flex flex-wrap gap-3 text-xs text-slate-brand mb-4">
                    <span>{{ $share->password_hash ? 'Protected' : 'No password' }}</span>
                    <span>{{ $share->expires_at ? 'Expires ' . $share->expires_at->format('M j, Y') : 'Never' }}</span>
                </div>

                {{-- Update form: password + expiry --}}
                <form method="POST" action="{{ route('shared-research.update', $share) }}" class="space-y-3 mb-4 p-3 rounded-xl bg-memory-violet/5 border border-memory-violet/10">
                    @csrf
                    @method('PATCH')
                    <div class="flex flex-wrap gap-3 items-end">
                        <div class="min-w-[140px] flex-1">
                            <label for="password-{{ $share->id }}" class="sr-only">Password</label>
                            <input type="text" name="password" id="password-{{ $share->id }}" placeholder="New password (optional)" autocomplete="off" class="w-full rounded-lg border border-memory-violet/20 px-2 py-1.5 text-sm" />
                        </div>
                        <label class="flex items-center gap-1.5 text-sm text-slate-brand">
                            <input type="hidden" name="password_remove" value="0" />
                            <input type="checkbox" name="password_remove" value="1" class="rounded border-memory-violet/30" />
                            Remove password
                        </label>
                        <div>
                            <label for="expires_at-{{ $share->id }}" class="sr-only">Expires</label>
                            <input type="date" name="expires_at" id="expires_at-{{ $share->id }}" value="{{ $share->expires_at?->format('Y-m-d') }}" class="rounded-lg border border-memory-violet/20 px-2 py-1.5 text-sm" />
                        </div>
                        <button type="submit" class="text-xs font-medium text-deep-indigo bg-white border border-memory-violet/20 px-3 py-1.5 rounded-lg hover:bg-memory-violet/5">Update</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('shared-research.destroy', $share) }}" class="inline" onsubmit="return confirm('Revoke this share link? It will stop working immediately.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 hover:underline">Revoke</button>
                </form>
            </div>
        @empty
            <p class="text-sm text-slate-brand">No share links yet. Use the form above to share a document.</p>
        @endforelse
    </div>
</div>

@if ($shares->isNotEmpty())
@push('scripts')
<script>
document.querySelectorAll('.copy-url-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var url = this.getAttribute('data-copy-url');
        navigator.clipboard.writeText(url).then(function() {
            var label = btn.textContent;
            btn.textContent = 'Copied';
            btn.disabled = true;
            setTimeout(function() {
                btn.textContent = label;
                btn.disabled = false;
            }, 1500);
        });
    });
});
@if ($focusShareId)
document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('share-{{ $focusShareId }}');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
@endif
</script>
@endpush
@endif
@endsection
