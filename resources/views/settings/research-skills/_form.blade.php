@php
    $skillModel = $skill ?? null;
    $latestModel = $latest ?? null;
    $intensityDefault = old('intensity', $latestModel?->intensity ?? 'standard');
@endphp

<div class="space-y-4">
    <div>
        <label for="name" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Name</label>
        <input
            type="text"
            name="name"
            id="name"
            value="{{ old('name', $skillModel?->name) }}"
            required
            maxlength="255"
            class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
        />
        @error('name')
            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="description" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Description (optional)</label>
        <input
            type="text"
            name="description"
            id="description"
            value="{{ old('description', $skillModel?->description) }}"
            maxlength="2000"
            class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
        />
        @error('description')
            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <input type="hidden" name="workflow_type" value="quick_brief" />
    @error('workflow_type')
        <p class="text-xs text-red-500">{{ $message }}</p>
    @enderror

    <div>
        <label for="instructions" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Instructions</label>
        <textarea
            name="instructions"
            id="instructions"
            rows="6"
            class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
        >{{ old('instructions', $latestModel?->instructions ?? '') }}</textarea>
        @error('instructions')
            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="context_options_json" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Context options (JSON object, optional)</label>
        <textarea
            name="context_options_json"
            id="context_options_json"
            rows="4"
            placeholder='e.g. {"include_idea": true}'
            class="w-full font-mono text-xs rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
        >{{ old('context_options_json', $latestModel && $latestModel->context_options !== null ? json_encode($latestModel->context_options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') }}</textarea>
        @error('context_options_json')
            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="output_shape_json" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Output structure (JSON object, optional)</label>
        <textarea
            name="output_shape_json"
            id="output_shape_json"
            rows="4"
            placeholder='e.g. {"sections": ["summary"]}'
            class="w-full font-mono text-xs rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
        >{{ old('output_shape_json', $latestModel && $latestModel->output_shape !== null ? json_encode($latestModel->output_shape, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') }}</textarea>
        @error('output_shape_json')
            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="intensity" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Intensity</label>
        <select
            name="intensity"
            id="intensity"
            class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
        >
            @foreach (['concise' => 'Concise', 'standard' => 'Standard', 'thorough' => 'Thorough'] as $value => $label)
                <option value="{{ $value }}" @selected($intensityDefault === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('intensity')
            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <div class="space-y-2 pt-2 border-t border-memory-violet/10">
        <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80">Behaviour</p>
        @php
            $manual = old('is_manual_enabled', $skillModel?->is_manual_enabled ?? true ? '1' : '0');
            $auto = old('allow_auto_run', $skillModel?->allow_auto_run ?? false ? '1' : '0');
            $default = old('is_default', $skillModel?->is_default ?? false ? '1' : '0');
            $active = old('is_active', $skillModel?->is_active ?? true ? '1' : '0');
        @endphp
        <label class="flex items-center gap-2 text-sm text-deep-indigo">
            <input type="hidden" name="is_manual_enabled" value="0" />
            <input type="checkbox" name="is_manual_enabled" value="1" class="rounded border-memory-violet/30" @checked($manual === '1' || $manual === true || $manual === 1) />
            Manual run enabled
        </label>
        <label class="flex items-center gap-2 text-sm text-deep-indigo">
            <input type="hidden" name="allow_auto_run" value="0" />
            <input type="checkbox" name="allow_auto_run" value="1" class="rounded border-memory-violet/30" @checked($auto === '1' || $auto === true || $auto === 1) />
            Allow auto-run (when eligible)
        </label>
        <label class="flex items-center gap-2 text-sm text-deep-indigo">
            <input type="hidden" name="is_default" value="0" />
            <input type="checkbox" name="is_default" value="1" class="rounded border-memory-violet/30" @checked($default === '1' || $default === true || $default === 1) />
            Default skill
        </label>
        <label class="flex items-center gap-2 text-sm text-deep-indigo">
            <input type="hidden" name="is_active" value="0" />
            <input type="checkbox" name="is_active" value="1" class="rounded border-memory-violet/30" @checked($active === '1' || $active === true || $active === 1) />
            Active
        </label>
        @error('is_manual_enabled')
            <p class="text-xs text-red-500">{{ $message }}</p>
        @enderror
        @error('allow_auto_run')
            <p class="text-xs text-red-500">{{ $message }}</p>
        @enderror
        @error('is_default')
            <p class="text-xs text-red-500">{{ $message }}</p>
        @enderror
        @error('is_active')
            <p class="text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>
</div>
