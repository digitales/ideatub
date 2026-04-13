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
</div>
