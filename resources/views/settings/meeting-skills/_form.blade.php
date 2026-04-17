@php
    $skillModel = $skill ?? null;
    $latestModel = $latest ?? null;
    $intensityDefault = old('intensity', $latestModel?->intensity ?? 'standard');
    $selectedOutputSections = old('output_sections', is_array($latestModel?->output_shape ?? null) ? ($latestModel->output_shape['sections'] ?? []) : []);
    $selectedOutputSections = is_array($selectedOutputSections) ? $selectedOutputSections : [];
    $selectedCoreCategories = old('core_categories', $latestModel?->core_categories ?? []);
    $selectedCoreCategories = is_array($selectedCoreCategories) ? $selectedCoreCategories : [];
    $customCategoriesLines = old('custom_categories_text');
    if ($customCategoriesLines === null && is_array($latestModel?->custom_categories ?? null)) {
        $customCategoriesLines = implode("\n", $latestModel->custom_categories);
    }
    $customCategoriesLines = $customCategoriesLines ?? '';
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

    <input type="hidden" name="workflow_type" value="meeting_brief" />
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
        <p class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Output structure</p>
        <p class="text-[11px] text-slate-brand/70 mb-2">Sections in the meeting brief (order is preserved).</p>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ([
                'summary' => 'Summary',
                'positives' => 'Positives',
                'things_to_watch' => 'Things to watch',
                'actions' => 'Actions',
                'conclusion' => 'Conclusion',
            ] as $value => $label)
                <label class="flex items-center gap-2 text-sm text-deep-indigo">
                    <input
                        type="checkbox"
                        name="output_sections[]"
                        value="{{ $value }}"
                        class="rounded border-memory-violet/30"
                        @checked(in_array($value, $selectedOutputSections, true))
                    />
                    {{ $label }}
                </label>
            @endforeach
        </div>
        @error('output_sections')
            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
        @enderror
        @error('output_sections.*')
            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <p class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Core categories</p>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ([
                'decisions' => 'Decisions',
                'action_items' => 'Action items',
                'risks' => 'Risks',
                'blockers' => 'Blockers',
                'follow_ups' => 'Follow-ups',
            ] as $value => $label)
                <label class="flex items-center gap-2 text-sm text-deep-indigo">
                    <input
                        type="checkbox"
                        name="core_categories[]"
                        value="{{ $value }}"
                        class="rounded border-memory-violet/30"
                        @checked(in_array($value, $selectedCoreCategories, true))
                    />
                    {{ $label }}
                </label>
            @endforeach
        </div>
        @error('core_categories')
            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="custom_categories_text" class="block text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-1.5">Custom categories (optional)</label>
        <p class="text-[11px] text-slate-brand/70 mb-2">One label per line.</p>
        <textarea
            name="custom_categories_text"
            id="custom_categories_text"
            rows="3"
            class="w-full rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
        >{{ $customCategoriesLines }}</textarea>
        @error('custom_categories')
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
            $manual = old('is_manual_enabled', (($skillModel?->is_manual_enabled ?? true) ? '1' : '0'));
            $auto = old('allow_auto_run', (($skillModel?->allow_auto_run ?? false) ? '1' : '0'));
            $default = old('is_default', (($skillModel?->is_default ?? false) ? '1' : '0'));
            $active = old('is_active', (($skillModel?->is_active ?? true) ? '1' : '0'));
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
