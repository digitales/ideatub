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
    $detailMarkdownRead = (bool) ($detailMarkdownRead ?? false);
    $contentHtmlInitial = $contentHtml ?? '';
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
        detailMarkdownRead: @js($detailMarkdownRead),
    })"
    x-on:thought-edit-requested.window="if ($event.detail?.thoughtId === @js((string) $thought->id)) startEdit()"
>
    @if ($detailMarkdownRead)
        <div x-show="!editing">
            @if ($editable)
                <div class="flex justify-end mb-2">
                    <button
                        type="button"
                        class="text-[12px] font-medium text-slate-brand hover:text-deep-indigo"
                        @click="startEdit()"
                        aria-label="Edit content"
                    >Edit</button>
                </div>
            @endif
            <div
                class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[14px] md:text-[15px]"
                x-ref="markdownReadBody"
            >{!! $contentHtmlInitial !!}</div>
        </div>
        <div
            x-show="editing"
            x-on:keydown.escape.stop.prevent="handleEditEscape()"
            :class="focusOverlayOpen ? 'fixed inset-0 z-50 flex flex-col p-6 ideatub-focus-shell' : 'mb-2'"
        >
            <div
                x-show="focusOverlayOpen"
                x-cloak
                @click="focusOverlayOpen = false; document.body.style.overflow = ''"
                class="ideatub-focus-backdrop"
                aria-hidden="true"
            ></div>
            <div :class="focusOverlayOpen ? 'max-w-4xl w-full mx-auto flex flex-col flex-1 min-h-0' : ''" :role="focusOverlayOpen ? 'dialog' : null" :aria-modal="focusOverlayOpen ? 'true' : null" :aria-label="focusOverlayOpen ? 'Edit thought' : null">
                <textarea x-ref="editTextarea" x-model="draftContent" rows="4" @input="resizeTextarea()" class="{{ $editorClass }} resize-none overflow-hidden" :class="focusOverlayOpen ? 'ideatub-focus-textarea overflow-auto' : ''"></textarea>
                <p x-show="error" x-text="error" class="text-[11px] text-red-600 mt-1"></p>
                <div class="flex items-center gap-2 mt-2">
                    <button type="button" @click="saveEdit()" :disabled="saveDisabled" class="text-[11px] font-medium text-white px-2 py-1 rounded bg-memory-violet disabled:opacity-50">Save</button>
                    <button type="button" @click="cancelEdit()" :disabled="saving" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo">Cancel</button>
                    <button type="button" x-show="!focusOverlayOpen" @click="toggleFocus()" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo ml-auto">Focus</button>
                    <button type="button" x-show="focusOverlayOpen" x-cloak @click="toggleFocus()" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo ml-auto">Close</button>
                </div>
            </div>
        </div>
    @else
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
            <div x-on:keydown.escape.stop.prevent="handleEditEscape()" :class="focusOverlayOpen ? 'fixed inset-0 z-50 flex flex-col p-6 ideatub-focus-shell' : 'mb-2'">
                <div
                    x-show="focusOverlayOpen"
                    x-cloak
                    @click="focusOverlayOpen = false; document.body.style.overflow = ''"
                    class="ideatub-focus-backdrop"
                    aria-hidden="true"
                ></div>
                <div :class="focusOverlayOpen ? 'max-w-4xl w-full mx-auto flex flex-col flex-1 min-h-0' : ''" :role="focusOverlayOpen ? 'dialog' : null" :aria-modal="focusOverlayOpen ? 'true' : null" :aria-label="focusOverlayOpen ? 'Edit thought' : null">
                    <textarea x-ref="editTextarea" x-model="draftContent" rows="4" @input="resizeTextarea()" class="{{ $editorClass }} resize-none overflow-hidden" :class="focusOverlayOpen ? 'ideatub-focus-textarea overflow-auto' : ''"></textarea>
                    <p x-show="error" x-text="error" class="text-[11px] text-red-600 mt-1"></p>
                    <div class="flex items-center gap-2 mt-2">
                        <button type="button" @click="saveEdit()" :disabled="saveDisabled" class="text-[11px] font-medium text-white px-2 py-1 rounded bg-memory-violet disabled:opacity-50">Save</button>
                        <button type="button" @click="cancelEdit()" :disabled="saving" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo">Cancel</button>
                        <button type="button" x-show="!focusOverlayOpen" @click="toggleFocus()" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo ml-auto">Focus</button>
                        <button type="button" x-show="focusOverlayOpen" x-cloak @click="toggleFocus()" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo ml-auto">Close</button>
                    </div>
                </div>
            </div>
        </template>
    @endif
</div>
