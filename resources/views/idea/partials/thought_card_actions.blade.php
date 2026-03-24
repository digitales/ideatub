@php
    $editable = $editable ?? (auth()->check() && auth()->id() === $thought->user_id);
    $share = $share ?? null;
    $isRootThought = $thought->parent_id === null;
@endphp
@if ($editable)
<div
    class="relative ml-auto inline-flex"
    x-data="thoughtCardActions('{{ e(route('ideas.destroy', $thought)) }}', '{{ e($thought->id) }}')"
    @click.outside="closeMenu(); cancelConfirm()"
>
    {{-- Menu button --}}
    <button
        type="button"
        @click="menuOpen = !menuOpen; if (confirmOpen) cancelConfirm()"
        class="p-1 rounded text-slate-brand/50 hover:text-slate-brand hover:bg-slate-brand/5 transition-colors"
        aria-label="Actions"
        aria-haspopup="true"
        :aria-expanded="menuOpen"
    >⋮</button>

    {{-- Dropdown --}}
    <div
        x-show="menuOpen"
        x-transition
        class="absolute right-0 top-full mt-0.5 py-1 min-w-[8rem] rounded-lg border border-memory-violet/15 bg-white shadow-lg z-10"
    >
        @if ($isRootThought)
            @if ($share)
                <a
                    href="{{ url(route('shared-research.show', $share->token)) }}"
                    target="_blank"
                    rel="noopener"
                    class="block px-3 py-1.5 text-[12px] text-memory-violet hover:bg-memory-violet/5 rounded"
                >Open link</a>
                <button
                    type="button"
                    data-copy-url="{{ $share ? e(url(route('shared-research.show', $share->token))) : '' }}"
                    @click="navigator.clipboard.writeText($el.getAttribute('data-copy-url')); $el.textContent='Copied!'; setTimeout(() => { $el.textContent='Copy link'; }, 1500)"
                    class="w-full text-left px-3 py-1.5 text-[12px] text-slate-brand hover:bg-slate-brand/5 rounded"
                >Copy link</button>
                <a
                    href="{{ route('shared-research.index', ['share' => $share->id]) }}"
                    class="block px-3 py-1.5 text-[12px] text-slate-brand hover:bg-slate-brand/5 rounded"
                >Manage</a>
            @else
                <a
                    href="{{ route('shared-research.index', ['create' => $thought->id]) }}"
                    class="block px-3 py-1.5 text-[12px] text-slate-brand hover:bg-slate-brand/5 rounded"
                >Share</a>
            @endif
        @endif
        <button
            type="button"
            @click="requestEdit()"
            class="w-full text-left px-3 py-1.5 text-[12px] text-slate-brand hover:bg-slate-brand/5 rounded"
        >Edit</button>
        <button
            type="button"
            @click="showConfirm()"
            class="w-full text-left px-3 py-1.5 text-[12px] text-red-600 hover:bg-red-50 rounded"
        >Delete</button>
    </div>

    {{-- Inline confirmation --}}
    <div
        x-show="confirmOpen"
        x-transition
        class="absolute right-0 top-full mt-1 p-2 rounded-lg border border-memory-violet/15 bg-white shadow z-10 min-w-[12rem]"
    >
        <p class="text-[12px] text-slate-brand mb-2">Delete thought?</p>
        <p x-show="error" x-text="error" class="text-[11px] text-red-600 mb-2"></p>
        <div class="flex gap-2">
            <button
                type="button"
                @click="cancelConfirm()"
                :disabled="deleting"
                class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo"
            >Cancel</button>
            <button
                type="button"
                @click="submitDelete()"
                :disabled="deleting"
                class="text-[11px] font-medium text-white px-2 py-1 rounded bg-red-600 hover:bg-red-700 disabled:opacity-50"
            >Delete</button>
        </div>
    </div>
</div>
@endif
