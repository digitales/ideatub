@extends('layouts.idea')

@section('title', $project->title.' — Share — IdeaTub')

@section('content')
<div class="max-w-[720px] mx-auto px-6 pt-16 pb-24">
    <div class="mb-8">
        <a href="{{ route('projects.show', $project) }}" class="text-sm text-memory-violet hover:underline">← Back to project</a>
        <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mt-2">Share “{{ $project->title }}”</h1>
        <p class="text-sm text-slate-brand mt-2">Read-only links to the project hub, a single “read all” page, and each item. Optional password and expiry.</p>
    </div>

    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-8">
        <h2 class="text-lg font-semibold text-deep-indigo mb-4">New share link</h2>
        <form method="POST" action="{{ route('projects.shares.store', $project) }}" class="space-y-4">
            @csrf
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
                    <input type="datetime-local" name="expires_at" id="expires_at" value="{{ old('expires_at') }}" class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo focus:border-neural-teal focus:ring-1 focus:ring-neural-teal" />
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

    <div class="space-y-4">
        <h2 class="text-lg font-semibold text-deep-indigo">Active links</h2>
        @forelse ($shares as $share)
            <div id="share-{{ $share->id }}" class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)] {{ (string) $focusShareId === (string) $share->id ? 'ring-2 ring-neural-teal' : '' }}">
                @php $hubUrl = url(route('shared-projects.hub', $share->token, absolute: false)); @endphp
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <a href="{{ $hubUrl }}" target="_blank" rel="noopener" class="text-xs font-mono text-memory-violet hover:underline bg-white/80 px-2 py-1 rounded break-all flex-1 min-w-0">{{ $hubUrl }}</a>
                    <button type="button" data-copy-url="{{ $hubUrl }}" class="copy-url-btn text-xs font-medium text-white px-3 py-1.5 rounded-lg transition-opacity hover:opacity-90 flex-shrink-0" style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
                        Copy
                    </button>
                </div>
                <div class="flex flex-wrap gap-3 text-xs text-slate-brand mb-4">
                    <span>{{ $share->password_hash ? 'Protected' : 'No password' }}</span>
                    <span>{{ $share->expires_at ? 'Expires '.$share->expires_at->format('M j, Y g:i A') : 'Never' }}</span>
                </div>

                <form method="POST" action="{{ route('project-shares.update', $share) }}" class="space-y-3 mb-4 p-3 rounded-xl bg-memory-violet/5 border border-memory-violet/10">
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
                            <input type="datetime-local" name="expires_at" id="expires_at-{{ $share->id }}" value="{{ $share->expires_at?->format('Y-m-d\TH:i') }}" class="rounded-lg border border-memory-violet/20 px-2 py-1.5 text-sm" />
                        </div>
                        <button type="submit" class="text-xs font-medium text-deep-indigo bg-white border border-memory-violet/20 px-3 py-1.5 rounded-lg hover:bg-memory-violet/5">Update</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('project-shares.destroy', $share) }}" class="inline" onsubmit="return confirm('Revoke this share link? It will stop working immediately.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 hover:underline">Revoke</button>
                </form>
            </div>
        @empty
            <p class="text-sm text-slate-brand">No share links yet.</p>
        @endforelse
    </div>
</div>

@if ($shares->isNotEmpty())
@push('scripts')
<script>
document.querySelectorAll('.copy-url-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-copy-url');
        if (!url) return;
        navigator.clipboard.writeText(url).then(function () {
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = 'Copy'; }, 2000);
        });
    });
});
</script>
@endpush
@endif
@endsection
