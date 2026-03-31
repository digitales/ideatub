@php
    $editable = $editable ?? (auth()->check() && auth()->id() === $thought->user_id);
    $displayClass = trim(($displayClass ?? 'text-[13.5px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line') . ' break-words [overflow-wrap:anywhere]');
    $editorClass = $editorClass ?? 'w-full text-[13.5px] text-deep-indigo leading-relaxed rounded-lg border border-memory-violet/20 focus:border-memory-violet focus:ring-memory-violet/20';
    $previewMaxLength = $previewMaxLength ?? null;
    $previewMode = (bool) ($previewMode ?? false);
    $thoughtPreviewKey = 'thought-preview-'.$thought->id;
    $viewHref = $viewHref ?? null;
    $viewLinkClass = $viewLinkClass ?? '';
    $displayContent = isset($displayContent) ? $displayContent : $thought->content;
    $rawEditorContent = isset($rawEditorContent) ? $rawEditorContent : $displayContent;
@endphp

<!-- ideatub-thought-content-update:{{ route('ideas.update-content', $thought) }} -->

<div
    x-data="thoughtContentEditor({
        displayContent: @js($displayContent),
        rawEditorContent: @js($rawEditorContent),
        updateUrl: @js(route('ideas.update-content', $thought)),
        editable: @js($editable),
        previewMaxLength: @js($previewMaxLength),
        previewMode: @js($previewMode),
    })"
    x-on:thought-edit-requested.window="if ($event.detail?.thoughtId === @js((string) $thought->id)) startEdit()"
>
    <template x-if="!editing">
        @if ($previewMode)
            <div class="mb-2 min-w-0">
                @if ($viewHref)
                    <a href="{{ $viewHref }}" class="{{ $viewLinkClass }}">
                        <p
                            id="{{ $thoughtPreviewKey }}"
                            x-ref="previewRegion"
                            data-thought-preview-region="{{ $thoughtPreviewKey }}"
                            class="{{ $displayClass }}"
                            :class="previewExpanded ? '' : 'line-clamp-[15]'"
                            x-text="content"
                        ></p>
                    </a>
                @else
                    <p
                        id="{{ $thoughtPreviewKey }}"
                        x-ref="previewRegion"
                        data-thought-preview-region="{{ $thoughtPreviewKey }}"
                        class="{{ $displayClass }}"
                        :class="previewExpanded ? '' : 'line-clamp-[15]'"
                        x-text="content"
                    ></p>
                @endif
                <button
                    type="button"
                    x-show="previewHasOverflow"
                    x-cloak
                    class="mt-1 text-left text-[11px] font-medium text-memory-violet hover:text-memory-violet/80 hover:underline"
                    data-thought-preview-toggle="{{ $thoughtPreviewKey }}"
                    :aria-expanded="previewExpanded ? 'true' : 'false'"
                    aria-controls="{{ $thoughtPreviewKey }}"
                    @click="togglePreviewExpanded()"
                >
                    <span x-text="previewExpanded ? 'Show less' : 'Read more'"></span>
                </button>
            </div>
        @else
            <div>
                @if ($viewHref)
                    <a href="{{ $viewHref }}" class="{{ $viewLinkClass }}">
                        <p class="{{ $displayClass }}" x-text="viewContent"></p>
                    </a>
                @else
                    <p class="{{ $displayClass }}" x-text="viewContent"></p>
                @endif
            </div>
        @endif
    </template>

    <template x-if="editing">
        <div class="mb-2" x-on:keydown.escape.stop.prevent="cancelEdit()">
            <textarea x-model="draftContent" rows="4" class="{{ $editorClass }}"></textarea>
            <p x-show="error" x-text="error" class="text-[11px] text-red-600 mt-1"></p>
            <div class="flex items-center gap-2 mt-2">
                <button type="button" @click="saveEdit()" :disabled="saveDisabled" class="text-[11px] font-medium text-white px-2 py-1 rounded bg-memory-violet disabled:opacity-50">Save</button>
                <button type="button" @click="cancelEdit()" :disabled="saving" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo">Cancel</button>
            </div>
        </div>
    </template>
</div>
