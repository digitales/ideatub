@extends('layouts.idea')

@section('title', $project->title.' — Project — IdeaTub')

@section('content')
<div class="max-w-[640px] mx-auto px-6 pt-16 pb-24">
    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">{{ $project->title }}</h1>
            @if ($project->description)
                <div class="mt-3 prose prose-sm max-w-none text-slate-brand">
                    <x-safe-markdown :markdown="$project->description" />
                </div>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('projects.graph', $project) }}" class="text-sm font-medium text-memory-violet hover:underline">Graph</a>
            <a href="{{ route('projects.shares.index', $project) }}" class="text-sm font-medium text-memory-violet hover:underline">Share</a>
            <a href="{{ route('projects.edit', $project) }}" class="text-sm font-medium text-memory-violet hover:underline">Edit</a>
            <form method="POST" action="{{ route('projects.destroy', $project) }}" class="m-0 inline-flex items-center p-0" onsubmit="return confirm('Archive this project? Thoughts stay in your library.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="m-0 cursor-pointer border-0 bg-transparent p-0 text-sm font-medium text-red-600 hover:underline">Archive</button>
            </form>
        </div>
    </div>

    @includeWhen(config('features.working_memory_ui'), 'projects.partials.working-memory-module', ['project' => $project])

    @if (! empty($contextThought))
        @include('projects.partials.context-thought', [
            'project' => $project,
            'contextThought' => $contextThought,
            'editable' => true,
        ])
    @endif

    <section class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 mb-8">
        <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Add thought</h2>
        <form method="POST" action="{{ route('projects.thoughts.store', $project) }}" class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-stretch">
            @csrf
            <select name="thought_id" required class="min-w-0 w-full flex-1 rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo">
                <option value="">Choose a thought…</option>
                @foreach ($thoughtOptions as $t)
                    <option value="{{ $t->id }}">{{ \Illuminate\Support\Str::limit($t->content, 80) }}</option>
                @endforeach
            </select>
            <button type="submit" class="shrink-0 rounded-lg bg-memory-violet px-4 py-2 text-sm font-medium whitespace-nowrap text-white hover:opacity-90">Add</button>
        </form>
        @error('thought_id')
            <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </section>

    @if (config('features.file_upload'))
    <section
        x-data="mdDropZone({
            previewUrl: '{{ route('imports.preview-markdown') }}',
            importUrl: '{{ route('projects.import-markdown', $project) }}',
            csrfToken: '{{ csrf_token() }}',
        })"
        class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 mb-8"
        @dragover.prevent="onDragOver"
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop($event)"
    >
        <div
            class="border-2 border-dashed rounded-xl p-6 text-center transition-colors"
            :class="dragging ? 'border-memory-violet bg-memory-violet/5' : 'border-slate-brand/20'"
        >
            <p class="text-sm text-slate-brand/70">
                <span class="font-medium text-memory-violet">Drop .md files here</span> to import into this project
            </p>
        </div>

        <template x-if="skippedCount > 0 && !modalOpen">
            <p class="mt-2 text-xs text-amber-600" x-text="skippedCount + ' file(s) skipped — only .md supported'"></p>
        </template>

        {{-- Import Modal --}}
        <template x-if="modalOpen">
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="closeModal">
                <div class="bg-white rounded-2xl shadow-xl max-w-2xl w-full mx-4 max-h-[85vh] flex flex-col" @keydown.escape.window="closeModal">
                    <div class="px-6 pt-5 pb-4 border-b border-slate-brand/10">
                        <h3 class="text-lg font-semibold text-deep-indigo">Import Markdown Files</h3>

                        <div class="mt-3">
                            <label class="block text-xs font-medium text-slate-brand/70 mb-1">Content type</label>
                            <select
                                x-model="selectedType"
                                class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
                            >
                                <option value="thought">Thought</option>
                                <option value="meeting">Meeting</option>
                                <option value="research">Research</option>
                                <option value="plan">Plan</option>
                                <option value="decision">Decision</option>
                                <option value="spec">Spec</option>
                            </select>
                        </div>

                        <template x-if="skippedCount > 0">
                            <p class="mt-2 text-xs text-amber-600" x-text="skippedCount + ' file(s) skipped — only .md supported'"></p>
                        </template>
                    </div>

                    <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                        <template x-for="(file, index) in files" :key="index">
                            <div class="rounded-xl border border-memory-violet/10 bg-white/60 p-4">
                                <div class="flex items-center gap-2 mb-3">
                                    <input
                                        type="text"
                                        x-model="file.title"
                                        class="flex-1 rounded-lg border border-memory-violet/20 bg-white px-3 py-1.5 text-sm text-deep-indigo"
                                        placeholder="Title"
                                    />
                                    <button
                                        type="button"
                                        @click="removeFile(index)"
                                        class="text-xs text-slate-brand hover:text-red-600 shrink-0"
                                    >Remove</button>
                                </div>
                                <div class="prose prose-sm max-w-none text-slate-brand max-h-48 overflow-y-auto rounded-lg border border-slate-brand/10 bg-slate-50 p-3">
                                    <template x-if="file.previewHtml">
                                        <div x-html="file.previewHtml"></div>
                                    </template>
                                    <template x-if="!file.previewHtml && file.previewLoading">
                                        <p class="text-xs text-slate-brand/50">Loading preview…</p>
                                    </template>
                                    <template x-if="!file.previewHtml && !file.previewLoading">
                                        <pre class="whitespace-pre-wrap text-xs" x-text="file.content.substring(0, 2000)"></pre>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="px-6 py-4 border-t border-slate-brand/10 flex items-center justify-between">
                        <span class="text-xs text-slate-brand/50" x-text="files.length + ' file(s)'"></span>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                @click="closeModal"
                                class="rounded-lg border border-slate-brand/20 px-4 py-2 text-sm font-medium text-slate-brand hover:bg-slate-50"
                            >Cancel</button>
                            <button
                                type="button"
                                @click="submitImport"
                                :disabled="importing || files.length === 0"
                                class="rounded-lg bg-memory-violet px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
                                x-text="importing ? 'Importing…' : 'Import ' + files.length + ' file(s)'"
                            ></button>
                        </div>
                    </div>

                    <template x-if="importError">
                        <p class="px-6 pb-4 text-xs text-red-600" x-text="importError"></p>
                    </template>
                </div>
            </div>
        </template>
    </section>
    @endif

    <section>
        <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Members</h2>
        @if (($memberThoughts ?? $project->thoughts)->isEmpty() && empty($contextThought))
            <p class="text-sm text-slate-brand/70">No thoughts in this project yet.</p>
        @else
            <ul class="space-y-2">
                @foreach ($memberThoughts ?? $project->thoughts as $thought)
                    <li class="flex items-start justify-between gap-3 rounded-xl border border-memory-violet/10 bg-white/60 px-4 py-3">
                        <a href="{{ $thought->ideaTubViewUrl() }}" class="text-sm text-deep-indigo hover:text-memory-violet line-clamp-3 min-w-0">
                            @if ($thought->isMicrositeDocumentLayout())
                                {{ \App\Support\Research\MicrositePageLabel::forThought($thought) }}
                            @else
                            {{ \Illuminate\Support\Str::limit($thought->content, 200) }}
                            @endif
                        </a>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <form method="POST" action="{{ route('projects.context.store', $project) }}">
                                @csrf
                                <input type="hidden" name="thought_id" value="{{ $thought->id }}">
                                <button type="submit" class="text-xs font-medium text-neural-teal hover:underline" title="Pin as project context">Pin as context</button>
                            </form>
                            <form method="POST" action="{{ route('projects.thoughts.destroy', [$project, $thought]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs text-slate-brand hover:text-red-600">Remove</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
