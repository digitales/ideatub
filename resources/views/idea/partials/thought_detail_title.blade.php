@php
    $editable = $editable ?? false;
    $title = data_get($thought->metadata, 'title', '');
@endphp

<div
    x-data="thoughtTitleEditor(@js($title), @js(route('ideas.update-title', $thought)), @js($editable))"
    class="mb-3"
>
    <div x-show="!editing" class="flex items-baseline gap-2">
        <h1
            class="text-[22px] font-semibold leading-tight"
            :class="title ? 'text-deep-indigo' : 'text-slate-brand/50'"
            x-text="title || 'Untitled research'"
        ></h1>
        @if ($editable)
            <button
                type="button"
                @click="startEdit()"
                class="text-[12px] font-medium text-slate-brand/60 hover:text-memory-violet transition-colors shrink-0"
                aria-label="Edit title"
            >Edit</button>
        @endif
    </div>
    <div x-show="editing" x-cloak x-on:keydown.escape.stop.prevent="cancelEdit()">
        <input
            type="text"
            x-ref="titleInput"
            x-model="draft"
            maxlength="255"
            placeholder="Research title…"
            @keydown.enter.prevent="saveEdit()"
            @blur="saveEdit()"
            class="w-full text-[22px] font-semibold text-deep-indigo leading-tight rounded-lg border border-memory-violet/20 px-2 py-1 focus:border-memory-violet focus:ring-memory-violet/20"
        >
        <p x-show="error" x-text="error" class="text-[11px] text-red-600 mt-1"></p>
    </div>
</div>
