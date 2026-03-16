@php
    $editable = $editable ?? true;
    $tagColors = ['violet', 'teal', 'indigo'];
    $tagMap = [
        'violet' => 'bg-memory-violet/10 text-memory-violet',
        'teal'   => 'bg-neural-teal/10 text-neural-teal',
        'indigo' => 'bg-deep-indigo/8 text-slate-brand',
    ];
    $tags = $thought->metadata['tags'] ?? [];
@endphp
<div
    class="flex items-center gap-2 flex-wrap"
    x-data="thoughtTagRow({{ json_encode($tags) }}, '{{ e(route('ideas.update-tags', $thought)) }}', {{ $editable ? 'true' : 'false' }})"
    data-stream-base-url="{{ e(route('idea.stream')) }}"
>
    <template x-for="(tag, index) in tags" :key="index">
        <span class="inline-flex items-center gap-0.5">
            <a
                :href="streamBaseUrl + (streamBaseUrl.indexOf('?') !== -1 ? '&' : '?') + 'tag=' + slugify(tag)"
                class="text-[10px] font-medium px-2 py-0.5 rounded-full hover:opacity-90"
                :class="tagPillClasses[index % 3]"
                x-text="'#' + tag"
            ></a>
            @if ($editable)
                <button
                    type="button"
                    x-show="editing"
                    @click="remove(index)"
                    class="text-slate-brand/60 hover:text-red-500 text-[14px] leading-none p-0.5 rounded"
                    :aria-label="'Remove tag ' + tag"
                >×</button>
            @endif
        </span>
    </template>
    @if ($editable)
        <template x-if="!editing">
            <button
                type="button"
                @click="editing = true"
                class="text-[10px] font-medium text-slate-brand/60 hover:text-memory-violet transition-colors"
                aria-label="Edit tags"
            >Edit</button>
        </template>
        <template x-if="editing">
            <span class="inline-flex items-center gap-1.5 flex-wrap">
                <input
                    type="text"
                    x-ref="addInput"
                    placeholder="Add tag…"
                    aria-label="Add tag"
                    @keydown.enter.prevent="addFromInput()"
                    class="text-[10px] font-medium px-2 py-0.5 rounded-full border border-memory-violet/20 w-24 min-w-0"
                >
                <button
                    type="button"
                    @click="addFromInput()"
                    class="text-[10px] font-medium text-memory-violet hover:opacity-90"
                    aria-label="Add tag"
                >+ Tag</button>
                <button
                    type="button"
                    @click="editing = false; error = ''"
                    class="text-[10px] font-medium text-slate-brand/60 hover:text-slate-brand"
                    aria-label="Done editing tags"
                >Done</button>
            </span>
        </template>
        <p x-show="error" x-text="error" class="text-xs text-red-500 w-full"></p>
    @endif
</div>
