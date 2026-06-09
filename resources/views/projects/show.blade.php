@extends('layouts.idea')

@php
    $projectShow = \App\View\Presenters\Projects\ProjectShowPresenter::fromProject($project);
    $demoModeOn = app(\App\Services\DemoMode::class)->enabled();
@endphp

@section('title', $projectShow->pageTitle().' — Project — IdeaTub')

@section('content')
@php
    $memberList = $memberThoughts ?? $project->thoughts;
    $memberCount = $memberList->count() + (empty($contextThought) ? 0 : 1);
    $memberCountLabel = $memberCount === 1 ? '1 idea' : $memberCount.' ideas';
@endphp
<div class="max-w-7xl mx-auto px-6 pt-10 pb-20 w-full">
    @if (session('success'))
        <div class="mb-6 rounded-2xl bg-neural-teal/10 px-4 py-3 text-sm text-neural-teal ring-1 ring-neural-teal/20">
            {{ session('success') }}
        </div>
    @endif

    <header class="mb-8">
        <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90 mb-2">Project</p>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 max-w-[52ch]">
                <h1 class="text-3xl font-semibold tracking-tight text-deep-indigo">{{ $projectShow->pageTitle() }}</h1>
                @if ($projectShow->descriptionMarkdown())
                    <div class="mt-3 prose prose-sm max-w-none text-slate-brand">
                        <x-safe-markdown :markdown="$projectShow->descriptionMarkdown()" />
                    </div>
                @endif
                <p class="mt-4 text-sm text-slate-brand/65">
                    <span class="font-medium text-slate-brand/80">{{ $memberCountLabel }}</span>
                    <span class="mx-1.5 text-slate-brand/30">·</span>
                    Updated {{ $project->updated_at->diffForHumans() }}
                    @if (! empty($contextThought))
                        <span class="mx-1.5 text-slate-brand/30">·</span>
                        <span class="text-neural-teal">Context pinned</span>
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <a href="{{ route('projects.graph', $project) }}" class="inline-flex items-center rounded-xl px-3 py-2 text-sm font-medium text-deep-indigo ring-1 ring-deep-indigo/[0.08] bg-white hover:bg-white/80 transition">Graph</a>
                <a href="{{ route('projects.shares.index', $project) }}" class="inline-flex items-center rounded-xl px-3 py-2 text-sm font-medium text-deep-indigo ring-1 ring-deep-indigo/[0.08] bg-white hover:bg-white/80 transition">Share</a>
                <a href="{{ route('projects.edit', $project) }}" class="inline-flex items-center rounded-xl px-3 py-2 text-sm font-medium text-deep-indigo ring-1 ring-deep-indigo/[0.08] bg-white hover:bg-white/80 transition">Edit</a>
                <form method="POST" action="{{ route('projects.destroy', $project) }}" class="m-0" onsubmit="return confirm('Archive this project? Thoughts stay in your library.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center rounded-xl px-3 py-2 text-sm font-medium text-red-600 ring-1 ring-red-200/80 bg-white hover:bg-red-50 transition cursor-pointer">Archive</button>
                </form>
            </div>
        </div>
    </header>

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_min(100%,320px)] lg:items-start">
        <div class="min-w-0 space-y-8">
            @if (! empty($contextThought))
                @include('projects.partials.context-thought', [
                    'project' => $project,
                    'contextThought' => $contextThought,
                    'editable' => ! $demoModeOn,
                ])
            @endif

            @includeWhen(config('features.working_memory_ui') && ! $demoModeOn, 'projects.partials.working-memory-inline', [
                'project' => $project,
                'workingMemoryPayload' => $workingMemoryPayload ?? null,
            ])

            <section class="ideatub-surface px-5 py-5 sm:px-6">
                <div class="flex flex-wrap items-end justify-between gap-3 mb-1">
                    <div>
                        <h2 class="text-lg font-semibold text-deep-indigo">Contents</h2>
                        <p class="mt-1 text-sm text-slate-brand/70 max-w-[48ch]">Ideas, notes, and documents grouped in this project.</p>
                    </div>
                    @if ($memberList->isNotEmpty())
                        <p class="text-xs font-medium text-slate-brand/55">{{ $memberList->count() }} {{ $memberList->count() === 1 ? 'item' : 'items' }}</p>
                    @endif
                </div>

                @if ($memberList->isEmpty() && empty($contextThought))
                    <div class="mt-6 rounded-xl border border-dashed border-deep-indigo/10 px-6 py-10 text-center">
                        <p class="text-sm text-slate-brand/70 max-w-sm mx-auto">No thoughts in this project yet. Add an existing thought from the sidebar or import markdown files.</p>
                    </div>
                @elseif ($memberList->isEmpty())
                    <p class="mt-4 text-sm text-slate-brand/70">All project thoughts are shown in the pinned context above.</p>
                @else
                    <ul class="mt-4 divide-y divide-deep-indigo/[0.06] list-none pl-0">
                        @foreach ($memberList as $thought)
                            @include('projects.partials.member-thought-row', [
                                'project' => $project,
                                'thought' => $thought,
                            ])
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <aside class="min-w-0 space-y-6 lg:sticky lg:top-6">
            @if (! $demoModeOn)
            <section class="ideatub-surface px-5 py-5">
                <h2 class="text-sm font-semibold text-deep-indigo">Add thought</h2>
                <p class="mt-1 text-xs text-slate-brand/70">Link an existing top-level thought to this project.</p>
                <form method="POST" action="{{ route('projects.thoughts.store', $project) }}" class="mt-4 flex min-w-0 flex-col gap-3">
                    @csrf
                    <select name="thought_id" required class="ideatub-input w-full">
                        <option value="">Choose a thought…</option>
                        @foreach ($thoughtOptions as $t)
                            <option value="{{ $t->id }}">{{ \Illuminate\Support\Str::limit($t->content, 80) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="ideatub-btn-primary w-full">Add to project</button>
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
                class="ideatub-surface px-5 py-5"
                @dragover.prevent="onDragOver"
                @dragleave.prevent="onDragLeave"
                @drop.prevent="onDrop($event)"
            >
                <h2 class="text-sm font-semibold text-deep-indigo">Import markdown</h2>
                <p class="mt-1 text-xs text-slate-brand/70">Drop files to create new thoughts in this project.</p>
                <div
                    class="mt-4 border-2 border-dashed rounded-xl p-5 text-center transition-colors"
                    :class="dragging ? 'border-memory-violet bg-memory-violet/5' : 'border-deep-indigo/10'"
                >
                    <p class="text-sm text-slate-brand/70">
                        <span class="font-medium text-memory-violet">Drop .md files here</span>
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
            @endif
        </aside>
    </div>
</div>
@endsection
