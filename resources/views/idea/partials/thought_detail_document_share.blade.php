@php
    $thought = $thoughtDetail->thought();
    $share = $thoughtDetail->documentShare();
@endphp
<div class="mt-4 pt-4 border-t border-memory-violet/10">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Share</p>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[12px]">
        @if ($share)
            <a
                href="{{ url(route('shared-research.show', $share->token)) }}"
                target="_blank"
                rel="noopener"
                class="font-medium text-memory-violet hover:underline"
            >Open link</a>
            <button
                type="button"
                data-copy-url="{{ e(url(route('shared-research.show', $share->token))) }}"
                @click="navigator.clipboard.writeText($el.getAttribute('data-copy-url')); $el.textContent='Copied!'; setTimeout(() => { $el.textContent='Copy link'; }, 1500)"
                class="font-medium text-slate-brand hover:text-deep-indigo"
            >Copy link</button>
            <a
                href="{{ route('shared-research.index', ['share' => $share->id]) }}"
                class="font-medium text-slate-brand hover:text-deep-indigo"
            >Manage</a>
        @else
            <a
                href="{{ route('shared-research.index', ['create' => $thought->id]) }}"
                class="font-medium text-memory-violet hover:underline"
            >Create share link</a>
        @endif
    </div>
</div>
