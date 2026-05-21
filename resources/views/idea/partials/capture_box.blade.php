@php
    $placement = $placement ?? 'inline';
    $initialContent = $initialContent ?? '';
    $forceHomeVideoMode = $forceHomeVideoMode ?? false;
    $importUploadsEnabled = $importUploadsEnabled ?? false;
    $noChunkingFieldId = $placement === 'global' ? 'no_chunking_global' : 'no_chunking';
@endphp
    <div
        x-data="captureBox()"
        @ideatub-load-draft.window="if ($event.detail?.id) loadDraft($event.detail.id)"
        data-placement="{{ $placement }}"
        data-initial-content="{{ e($initialContent) }}"
        data-force-video-mode="{{ $forceHomeVideoMode ? '1' : '0' }}"
        data-videos-store-url="{{ route('videos.store') }}"
        data-focus-reply="{{ ($placement === 'inline' && isset($replyingTo) && $replyingTo) ? '1' : '0' }}"
        data-idea-index-url="{{ route('idea.index') }}"
        data-drafts-url="{{ route('ideas.drafts.index') }}"
        @if ($importUploadsEnabled)
            data-file-upload="1"
            data-import-quick-url="{{ route('imports.quick') }}"
            data-import-batch-url="{{ route('imports.batch') }}"
        @endif
        @if ($placement === 'inline')
        x-on:focus-capture.window="focusCapture()"
        class="ideatub-surface mb-3 p-4 transition focus-within:ring-memory-violet/30 dark:focus-within:ring-violet-400/40"
        :class="focusOverlayOpen ? 'ideatub-focus-shell' : ''"
        @click.self="focusOverlayOpen && closeFocusOverlay()"
        @else
        class="p-0"
        @endif
    >
        {{-- Focus mode: full-screen white backdrop (click to close) --}}
        <div
            x-show="focusOverlayOpen"
            x-cloak
            @click="closeFocusOverlay()"
            class="ideatub-focus-backdrop"
            aria-hidden="true"
        ></div>

        <div
            class="w-full"
            :class="focusOverlayOpen ? 'ideatub-focus-panel' : 'max-w-[600px]'"
            :role="focusOverlayOpen ? 'dialog' : null"
            :aria-modal="focusOverlayOpen ? 'true' : null"
            :aria-label="focusOverlayOpen ? 'Capture thought' : null"
        >
        {{-- AJAX success/error message --}}
        <div x-show="message" x-cloak class="mb-3 rounded-xl px-4 py-3 text-sm"
            :class="messageType === 'success' ? 'bg-neural-teal/10 border border-neural-teal/25 text-neural-teal' : 'bg-red-50 border border-red-200 text-red-600'"
            x-text="message">
        </div>

        {{-- Draft list (hidden in reply mode) --}}
        <div
            x-show="drafts.length > 0 && !isReplyMode"
            x-cloak
            class="mb-3"
        >
            <button
                type="button"
                @click="draftsExpanded = !draftsExpanded"
                class="text-sm text-slate-brand hover:text-deep-indigo font-medium"
            >
                Drafts (<span x-text="drafts.length"></span>)
            </button>
            <template x-if="draftsExpanded">
                <ul class="mt-2 space-y-2" role="list">
                    <template x-for="draft in drafts" :key="draft.id">
                        <li class="flex items-center justify-between gap-2 py-2 px-3 rounded-lg border border-memory-violet/15 bg-memory-violet/5 text-sm text-slate-brand">
                            <div class="min-w-0 flex-1">
                                <span class="line-clamp-1" x-text="draft.content_preview"></span>
                                <span class="text-[11px] text-slate-brand/60" x-text="draft.updated_at_human"></span>
                            </div>
                            <div class="shrink-0 flex items-center gap-1.5">
                                <button type="button" @click="loadDraft(draft.id)" class="text-xs font-medium text-memory-violet hover:text-deep-indigo">Resume</button>
                                <button type="button" @click="discardDraft(draft.id)" class="text-xs font-medium text-slate-brand/70 hover:text-red-600">Discard</button>
                            </div>
                        </li>
                    </template>
                </ul>
            </template>
        </div>

        <form
            method="POST"
            action="{{ route('thoughts.store') }}"
            @submit.prevent="submitCapture()"
            @keydown.meta.enter.prevent="submitCapture()"
            :class="focusOverlayOpen ? 'flex flex-col flex-1 min-h-0' : ''"
        >
            @csrf
            <input type="hidden" name="parent_id" value="{{ isset($replyingTo) && $replyingTo ? $replyingTo->id : '' }}">

            @if ($placement === 'inline' && isset($replyingTo) && $replyingTo)
                <div class="flex items-start gap-2 mb-3 px-3 py-2 rounded-lg border border-memory-violet/15 bg-memory-violet/5">
                    <div class="flex-1 min-w-0">
                        <span class="text-[11px] font-semibold uppercase tracking-wider text-memory-violet/80">Replying to</span>
                        <p class="text-sm text-deep-indigo mt-0.5 line-clamp-2">{{ $replyingToPreview }}</p>
                    </div>
                    <a href="{{ route('idea.index') }}" class="shrink-0 text-xs font-medium text-slate-brand hover:text-deep-indigo px-2.5 py-1.5 rounded-md border border-slate-200 hover:border-slate-300 bg-white/80 transition-colors" title="Cancel reply">Cancel</a>
                </div>
            @endif

            <textarea
                x-bind:name="videoMode && !isReplyMode ? 'youtube_url' : 'content'"
                id="{{ $placement === 'global' ? 'global-capture-content' : 'content' }}"
                rows="3"
                x-ref="captureTextarea"
                x-model="content"
                @keydown.meta.enter.prevent="submitCapture()"
                @keydown.ctrl.enter.prevent="submitCapture()"
                :aria-invalid="!!errorField || !!videoErrorField || {{ $errors->has('content') ? 'true' : 'false' }}"
                aria-describedby="content-error youtube-url-error"
                x-bind:placeholder="videoMode && !isReplyMode ? 'Paste a YouTube link…' : 'What are you thinking?'"
                class="w-full border-none outline-none resize-none text-sm text-deep-indigo placeholder-slate-brand/40 leading-relaxed"
                :class="focusOverlayOpen ? 'ideatub-focus-textarea' : 'bg-transparent'"
            ></textarea>

            <p id="content-error" class="mt-1 text-xs text-red-500" x-show="errorField || {{ $errors->has('content') ? 'true' : 'false' }}" x-text="errorField">@if($errors->has('content')){{ $errors->first('content') }}@endif</p>
            <p id="youtube-url-error" class="mt-1 text-xs text-red-500" x-show="videoErrorField" x-cloak x-text="videoErrorField"></p>
            @error('youtube_url')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror

            <div x-show="videoMode && !isReplyMode" x-cloak class="mt-3 space-y-3 rounded-lg border border-memory-violet/15 bg-memory-violet/5 px-3 py-3">
                <p class="text-xs text-deep-indigo leading-relaxed">
                    This will be saved as a <span class="font-medium">video thought</span> (not a regular capture). Leave transcript empty to try fetching from YouTube.
                </p>
                <label class="block">
                    <span class="text-[11px] font-medium text-memory-violet/90">Transcript (optional)</span>
                    <textarea
                        name="transcript"
                        rows="4"
                        x-model="videoTranscript"
                        placeholder="Paste a transcript if you already have one…"
                        class="mt-1 w-full rounded-lg border border-memory-violet/20 bg-white/90 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/40 focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50 resize-none"
                    ></textarea>
                </label>
                <label class="flex items-start gap-2 text-[11px] text-slate-brand/80">
                    <input type="checkbox" name="research_now" value="1" x-model="videoResearchNow" class="mt-0.5 rounded border-slate-300 text-memory-violet" />
                    <span>
                        <span class="font-medium text-deep-indigo">Research now</span>
                        <span class="block text-slate-brand/60">Video research runs after the transcript is ready.</span>
                    </span>
                </label>
            </div>

            <div class="mt-2 flex items-center gap-2" x-show="!videoMode || isReplyMode">
                <input type="checkbox" name="no_chunking" id="{{ $noChunkingFieldId }}" value="1" class="rounded border-slate-300 text-memory-violet focus:ring-memory-violet/30"
                    x-model="noChunking"
                    {{ old('no_chunking') ? 'checked' : '' }}>
                <label for="{{ $noChunkingFieldId }}" class="text-[11px] text-slate-brand/70">Don't split into sections (long docs are normally split at headings)</label>
            </div>

            <div class="flex items-center justify-between mt-2.5 pt-2.5 border-t border-memory-violet/8">
                <span class="text-[11px] text-slate-brand/40">
                    @if ($placement === 'global')
                        ⌘ + Enter to store · Escape to close
                    @else
                        ⌘ + Enter to store · ⌘/ to focus
                    @endif
                </span>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        x-ref="focusButton"
                        x-show="!focusOverlayOpen"
                        x-cloak
                        @click="toggleFocus()"
                        class="text-xs font-medium text-slate-brand hover:text-deep-indigo"
                    >
                        Focus
                    </button>
                    <button
                        type="button"
                        x-show="focusOverlayOpen"
                        x-cloak
                        @click="closeFocusOverlay()"
                        class="text-xs font-medium text-slate-brand hover:text-deep-indigo"
                    >
                        Close
                    </button>
                    <button
                        type="submit"
                        :disabled="saving"
                        class="text-xs font-medium text-white px-4 py-1.5 rounded-lg transition-opacity hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed"
                        style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                    >
                        <span x-show="!saving && (!videoMode || isReplyMode)">Store thought</span>
                        <span x-show="!saving && videoMode && !isReplyMode" x-cloak>Save video</span>
                        <span x-show="saving" x-cloak>Saving…</span>
                    </button>
                </div>
            </div>
        </form>

        @if ($importUploadsEnabled && ($placement === 'global' || ! isset($replyingTo)))
            <div
                class="mt-2 flex items-center justify-between gap-2 flex-wrap"
                data-capture-import-toolbar
            >
                <div class="flex items-center gap-1.5">
                    <span class="text-[11px] text-slate-brand/45">Files</span>
                    <input
                        type="file"
                        x-ref="importQuickInput"
                        class="sr-only"
                        accept=".txt,.md,.mdown,.markdown"
                        multiple
                        @change="onImportQuickPicked($event)"
                    />
                    <input
                        type="file"
                        x-ref="importFolderInput"
                        class="sr-only"
                        webkitdirectory
                        multiple
                        @change="onImportFolderPicked($event)"
                    />
                    <button
                        type="button"
                        class="p-1.5 rounded-md border border-memory-violet/15 text-slate-brand/70 hover:bg-memory-violet/5 hover:text-deep-indigo transition-colors"
                        @click="triggerImportFilePick()"
                        title="Import from files (up to 5)"
                    >
                        <span class="sr-only">Import files</span>
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-5.364 5.364a1.5 1.5 0 000 2.12l.707.707a1.5 1.5 0 102.12 0L18 6.12M3 17h1M6 4h.01" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        class="p-1.5 rounded-md border border-memory-violet/15 text-slate-brand/70 hover:bg-memory-violet/5 hover:text-deep-indigo transition-colors"
                        @click="triggerImportFolderPick()"
                        title="Import a folder (async batch)"
                    >
                        <span class="sr-only">Import folder</span>
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M4 8a2 2 0 012-2h3l1.4 1.4A2 2 0 0012.8 8H20a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V8z" />
                        </svg>
                    </button>
                </div>
            </div>
        @endif

        @if ($importUploadsEnabled && ($placement === 'global' || ! isset($replyingTo)))
            <div
                x-cloak
                x-show="importModalOpen"
                x-transition.opacity
                class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-4 bg-deep-indigo/20 backdrop-blur-sm"
                role="dialog"
                aria-modal="true"
                :aria-label="importModalKind === 'batch' ? 'Import folder' : 'Import files'"
            >
                <div
                    class="w-full max-w-sm rounded-2xl border border-memory-violet/20 bg-white/95 p-4 shadow-lg"
                    @click.outside="!importSubmitting && (importModalOpen = false)"
                >
                    <h2 class="text-sm font-semibold text-deep-indigo" x-text="importModalKind === 'batch' ? 'Import folder' : 'Import files'"></h2>
                    <p class="mt-2 text-xs text-slate-brand/80 leading-relaxed">
                        Files are scanned and stored like regular captures. Folder imports are processed in the background; you can watch progress on the next screen.
                    </p>
                    <div class="mt-3 space-y-2" x-show="importModalKind === 'batch'">
                        <label class="block">
                            <span class="text-[11px] font-medium text-slate-brand/70">Project name</span>
                            <input
                                type="text"
                                x-model="importBatchProjectTitle"
                                class="mt-1 w-full rounded-lg border border-memory-violet/20 px-3 py-1.5 text-sm text-deep-indigo"
                                placeholder="My notes"
                            />
                        </label>
                        <div class="flex items-center gap-2 text-xs text-slate-brand/80">
                            <input type="radio" id="importDedupeNew" x-model="importBatchDedupe" value="new" class="text-memory-violet" />
                            <label for="importDedupeNew">New project (rename if the title exists)</label>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-slate-brand/80">
                            <input type="radio" id="importDedupeEx" x-model="importBatchDedupe" value="existing" class="text-memory-violet" />
                            <label for="importDedupeEx">Add to an existing project with the same name</label>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-red-600/90" x-show="importError" x-text="importError"></p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button
                            type="button"
                            class="text-xs font-medium text-slate-brand/70 px-2 py-1.5"
                            :disabled="importSubmitting"
                            @click="importModalOpen = false; importError = ''"
                        >Cancel</button>
                        <button
                            type="button"
                            class="text-xs font-medium text-white px-3 py-1.5 rounded-lg disabled:opacity-50"
                            style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                            :disabled="importSubmitting"
                            @click="confirmImport()"
                        >Import</button>
                    </div>
                </div>
            </div>
        @endif
        </div>
    </div>
