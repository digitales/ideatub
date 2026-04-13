@php
    /** @var \App\Models\Thought $thought */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Project> $projectsToAttachToThought */
    $inActionsRow = $inActionsRow ?? false;
    $addToProjectDetailsClass = $inActionsRow
        ? 'group w-full min-w-0'
        : 'mt-4 rounded-xl border border-memory-violet/15 bg-memory-violet/[0.04] px-3 py-2';
    $addProjectOpen = $errors->has('project_id')
        || $errors->has('new_project_title')
        || $errors->has('new_project_description')
        || (old('project_id') !== null && old('project_id') !== '');
@endphp

<details class="{{ $addToProjectDetailsClass }}" @if ($addProjectOpen) open @endif>
    <summary
        class="@if ($inActionsRow) cursor-pointer list-none font-medium text-memory-violet hover:underline [&::-webkit-details-marker]:hidden @else cursor-pointer text-sm font-medium text-memory-violet select-none @endif"
    >
        Add to project
    </summary>
    <form
        method="POST"
        action="{{ route('thoughts.projects.store', $thought) }}"
        class="mt-3 w-full max-w-full space-y-3 @if ($inActionsRow) border-l border-memory-violet/15 pl-4 @endif"
        x-data="{ pick: @js(old('project_id', '')) }"
    >
        @csrf
        <div>
            <label for="attach-project-id-header" class="sr-only">Project</label>
            <select
                id="attach-project-id-header"
                name="project_id"
                required
                x-model="pick"
                class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
            >
                <option value="" disabled @if (! old('project_id')) selected @endif>Choose…</option>
                @foreach ($projectsToAttachToThought as $p)
                    <option value="{{ $p->id }}" @selected(old('project_id') === $p->id)>{{ $p->title }}</option>
                @endforeach
                <option value="__new__" @selected(old('project_id') === '__new__')>New project…</option>
            </select>
        </div>

        <div x-show="pick === '__new__'" x-cloak class="space-y-2">
            <div>
                <label for="new-project-title" class="block text-[10px] uppercase tracking-wider text-slate-brand/60 mb-1">Title</label>
                <input
                    type="text"
                    id="new-project-title"
                    name="new_project_title"
                    value="{{ old('new_project_title') }}"
                    maxlength="255"
                    class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
                    placeholder="Project name"
                />
            </div>
            <div>
                <label for="new-project-description" class="block text-[10px] uppercase tracking-wider text-slate-brand/60 mb-1">Description <span class="normal-case">(optional)</span></label>
                <textarea
                    id="new-project-description"
                    name="new_project_description"
                    rows="3"
                    class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
                    placeholder="Markdown supported">{{ old('new_project_description') }}</textarea>
            </div>
        </div>

        @error('project_id')
            <p class="text-xs text-red-600">{{ $message }}</p>
        @enderror
        @error('new_project_title')
            <p class="text-xs text-red-600">{{ $message }}</p>
        @enderror
        @error('new_project_description')
            <p class="text-xs text-red-600">{{ $message }}</p>
        @enderror

        <button type="submit" class="rounded-lg bg-memory-violet text-white text-sm font-medium px-4 py-2 hover:opacity-90">
            Add to project
        </button>
    </form>
</details>
