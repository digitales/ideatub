<div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Thought detail</p>

    <div class="flex items-center gap-2 flex-wrap">
        @include('idea.partials.thought_type_badge', [
            'thought' => $thought,
            'class' => 'text-[11px] font-medium uppercase tracking-[0.08em] text-slate-brand/60',
            'fallbackLabel' => 'Thought',
        ])
        <span class="text-[11px] text-slate-brand/40">{{ $thought->created_at->diffForHumans() }}</span>
    </div>

    <div class="mt-4">
        @include('idea.partials.thought_tag_row', ['thought' => $thought, 'editable' => true])
    </div>
</div>
