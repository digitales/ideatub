@php
    $share = $share ?? null;
    $returnTo = $returnTo ?? url()->current();
    $placement = $placement ?? 'card';
    $isDetail = $placement === 'detail';
    $shareUrl = $share ? url(route('shared-research.show', $share->token)) : null;
    $openModalOnLoad = $errors->has('password') || $errors->has('expires_at') || $errors->has('thought_id');
@endphp
<div
    class="document-share-widget {{ $isDetail ? '' : 'inline-flex items-center gap-1.5' }}"
    x-data="{ shareModalOpen: @json($openModalOnLoad) }"
    data-document-share
>
    @if (session('document_share_url'))
        <div class="mb-2 w-full rounded-lg bg-neural-teal/10 border border-neural-teal/25 px-3 py-2 text-[11px] text-neural-teal flex flex-wrap items-center gap-2">
            <span class="flex-1 min-w-0 truncate font-mono">{{ session('document_share_url') }}</span>
            <button
                type="button"
                data-copy-url="{{ e(session('document_share_url')) }}"
                @click="navigator.clipboard.writeText($el.getAttribute('data-copy-url')); $el.textContent='Copied!'; setTimeout(() => { $el.textContent='Copy'; }, 1500)"
                class="shrink-0 font-medium text-memory-violet hover:underline"
            >Copy</button>
        </div>
    @endif

    @if ($share)
        <span class="inline-flex items-center rounded-full bg-neural-teal/10 border border-neural-teal/25 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-neural-teal">
            Shared
        </span>
        <button
            type="button"
            data-copy-url="{{ e($shareUrl) }}"
            @click="navigator.clipboard.writeText($el.getAttribute('data-copy-url')); $el.textContent='Copied!'; setTimeout(() => { $el.textContent='Copy link'; }, 1500)"
            class="text-[11px] font-medium text-memory-violet hover:underline"
        >Copy link</button>
        @if ($isDetail)
            <a
                href="{{ $shareUrl }}"
                target="_blank"
                rel="noopener"
                class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo"
            >Open</a>
            <a
                href="{{ route('shared-research.index', ['share' => $share->id]) }}"
                class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo"
            >Manage</a>
        @else
            <a
                href="{{ $shareUrl }}"
                target="_blank"
                rel="noopener"
                class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo"
                title="Open shared link"
            >Open</a>
        @endif
    @else
        <button
            type="button"
            @click="shareModalOpen = true"
            class="text-[11px] font-medium text-memory-violet hover:underline"
        >Share</button>
    @endif

    <div
        x-cloak
        x-show="shareModalOpen"
        x-transition.opacity
        class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-4 bg-deep-indigo/20 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-label="Create share link"
    >
        <div
            class="w-full max-w-md rounded-2xl border border-memory-violet/20 bg-white/95 p-5 shadow-lg"
            @click.outside="shareModalOpen = false"
        >
            <h2 class="text-sm font-semibold text-deep-indigo">Create share link</h2>
            <p class="mt-1 text-xs text-slate-brand/80">Read-only link with optional password and expiry.</p>

            @if (session('error'))
                <p class="mt-2 text-xs text-red-600/90">{{ session('error') }}</p>
            @endif

            <form method="POST" action="{{ route('shared-research.store') }}" class="mt-4 space-y-3">
                @csrf
                <input type="hidden" name="thought_id" value="{{ $thought->id }}" />
                <input type="hidden" name="return_to" value="{{ $returnTo }}" />

                <div>
                    <label for="share-password-{{ $thought->id }}" class="block text-xs font-medium text-deep-indigo mb-1">Password (optional)</label>
                    <input
                        type="text"
                        name="password"
                        id="share-password-{{ $thought->id }}"
                        value="{{ old('password') }}"
                        placeholder="Leave blank for no password"
                        autocomplete="off"
                        class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/60 focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                    />
                    @error('password')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="share-expires-{{ $thought->id }}" class="block text-xs font-medium text-deep-indigo mb-1">Expires (optional)</label>
                    <input
                        type="date"
                        name="expires_at"
                        id="share-expires-{{ $thought->id }}"
                        value="{{ old('expires_at') }}"
                        min="{{ date('Y-m-d', strtotime('+1 day')) }}"
                        class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo focus:border-neural-teal focus:ring-1 focus:ring-neural-teal"
                    />
                    @error('expires_at')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                    @error('thought_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button
                        type="button"
                        class="text-xs font-medium text-slate-brand/70 px-2 py-1.5"
                        @click="shareModalOpen = false"
                    >Cancel</button>
                    <button
                        type="submit"
                        class="text-xs font-medium text-white px-3 py-1.5 rounded-lg transition-opacity hover:opacity-90"
                        style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                    >Create link</button>
                </div>
            </form>
        </div>
    </div>
</div>
