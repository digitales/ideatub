<div class="space-y-4">
    <div>
        <label for="project-title" class="block text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/80 mb-1.5">Title</label>
        <input
            id="project-title"
            name="title"
            type="text"
            required
            maxlength="255"
            value="{{ old('title', $project->title) }}"
            class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
        />
        @error('title')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
    <div>
        <label for="project-description" class="block text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/80 mb-1.5">Description <span class="font-normal normal-case text-slate-brand/60">(markdown)</span></label>
        <textarea
            id="project-description"
            name="description"
            rows="5"
            class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50 resize-y"
        >{{ old('description', $project->description) }}</textarea>
        @error('description')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
    <div>
        <label for="project-elixirr-client-slug" class="block text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/80 mb-1.5">Elixirr client slug</label>
        <input
            id="project-elixirr-client-slug"
            name="elixirr_client_slug"
            type="text"
            maxlength="64"
            pattern="[a-z0-9-]+"
            value="{{ old('elixirr_client_slug', $project->elixirr_client_slug) }}"
            class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
            placeholder="dezeen"
        />
        <p class="mt-1 text-xs text-slate-brand/70">Lowercase letters, numbers, and hyphens only.</p>
        @error('elixirr_client_slug')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
    <div>
        <label for="project-elixirr-project-slug" class="block text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/80 mb-1.5">Elixirr project slug</label>
        <input
            id="project-elixirr-project-slug"
            name="elixirr_project_slug"
            type="text"
            maxlength="64"
            pattern="[a-z0-9-]+"
            value="{{ old('elixirr_project_slug', $project->elixirr_project_slug) }}"
            class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
            placeholder="foo"
        />
        @error('elixirr_project_slug')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
    <div>
        <label for="project-parent-id" class="block text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/80 mb-1.5">Parent project</label>
        <select
            id="project-parent-id"
            name="parent_project_id"
            class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
        >
            <option value="">None</option>
            @foreach ($parentProjectOptions ?? [] as $parentOption)
                <option
                    value="{{ $parentOption->id }}"
                    @selected((string) old('parent_project_id', $project->parent_project_id) === (string) $parentOption->id)
                >
                    {{ $parentOption->title }}
                    @if ($parentOption->elixirr_client_slug)
                        ({{ $parentOption->elixirr_client_slug }})
                    @endif
                </option>
            @endforeach
        </select>
        @error('parent_project_id')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
</div>
