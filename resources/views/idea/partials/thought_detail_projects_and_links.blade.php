@php
    use App\Enums\ThoughtLinkType;
@endphp

<div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] space-y-8">
    <section>
        <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Projects</h2>
        @if ($thoughtProjectsForDetail->isEmpty())
            <p class="text-sm text-slate-brand/70 mb-3">Not in any project yet.</p>
        @else
            <ul class="flex flex-wrap gap-2 mb-3">
                @foreach ($thoughtProjectsForDetail as $p)
                    <li class="inline-flex items-center gap-1 rounded-full border border-memory-violet/20 bg-memory-violet/5 pl-3 pr-1 py-1 text-xs text-deep-indigo">
                        <a href="{{ route('projects.show', $p) }}" class="hover:text-memory-violet hover:underline">{{ $p->title }}</a>
                        @if ($editable ?? true)
                            <form method="POST" action="{{ route('thoughts.projects.destroy', [$thought, $p]) }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-full p-1 text-slate-brand/50 hover:text-red-600 hover:bg-red-50" title="Remove from project">×</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @php
        $linkSectionOpen = $errors->has('to_thought_id') || $errors->has('link_type') || $errors->has('note');
    @endphp
    <section>
        <details class="group" @if ($linkSectionOpen) open @endif>
            <summary class="cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80">
                    Linked thoughts
                </span>
            </summary>
            <div class="mt-4 space-y-4">
        @if ($thoughtOutgoingLinks->isNotEmpty())
            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-brand/50 mb-2">From this thought</p>
            <ul class="space-y-2 mb-4">
                @foreach ($thoughtOutgoingLinks as $link)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 rounded-lg border border-memory-violet/10 bg-white/60 px-3 py-2 text-sm">
                        <div class="min-w-0">
                            <span class="font-medium text-memory-violet">{{ ThoughtLinkType::from($link->link_type)->label() }}</span>
                            <span class="text-slate-brand/60 mx-1">→</span>
                            <a href="{{ route('thoughts.show', $link->toThought) }}" class="text-deep-indigo hover:underline break-words">{{ \Illuminate\Support\Str::limit($link->toThought->content, 100) }}</a>
                            @if ($link->note)
                                <p class="text-xs text-slate-brand/60 mt-1">{{ $link->note }}</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('thoughts.links.destroy', [$thought, $link]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-slate-brand hover:text-red-600 shrink-0">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($thoughtIncomingLinks->isNotEmpty())
            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-brand/50 mb-2">To this thought</p>
            <ul class="space-y-2 mb-4">
                @foreach ($thoughtIncomingLinks as $link)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 rounded-lg border border-memory-violet/10 bg-white/60 px-3 py-2 text-sm">
                        <div class="min-w-0">
                            <a href="{{ route('thoughts.show', $link->fromThought) }}" class="text-deep-indigo hover:underline break-words">{{ \Illuminate\Support\Str::limit($link->fromThought->content, 80) }}</a>
                            <span class="text-slate-brand/60 mx-1">→</span>
                            <span class="font-medium text-memory-violet">{{ ThoughtLinkType::from($link->link_type)->label() }}</span>
                            @if ($link->note)
                                <p class="text-xs text-slate-brand/60 mt-1">{{ $link->note }}</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('thoughts.links.destroy', [$thought, $link]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-slate-brand hover:text-red-600 shrink-0">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($linkTargetThoughtOptions->isNotEmpty())
            <form method="POST" action="{{ route('thoughts.links.store', $thought) }}" class="space-y-3 rounded-xl border border-dashed border-memory-violet/25 p-4">
                @csrf
                @if ($linkTargetThoughtOptionsUsedGlobalFallback ?? false)
                    <p class="text-xs text-slate-brand/70 mb-1">No other thoughts in your project(s) yet — showing all thoughts.</p>
                @endif
                <p class="text-xs font-medium text-slate-brand">New link <span class="text-slate-brand/60 font-normal">(from this thought)</span></p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="link-to-thought" class="block text-[10px] uppercase tracking-wider text-slate-brand/60 mb-1">Target thought</label>
                        <select id="link-to-thought" name="to_thought_id" required class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo">
                            <option value="">Choose…</option>
                            @foreach ($linkTargetThoughtOptions as $t)
                                <option value="{{ $t->id }}">{{ \Illuminate\Support\Str::limit($t->content, 72) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="link-type" class="block text-[10px] uppercase tracking-wider text-slate-brand/60 mb-1">Relationship</label>
                        <select id="link-type" name="link_type" required class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo">
                            @foreach (ThoughtLinkType::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label for="link-note" class="block text-[10px] uppercase tracking-wider text-slate-brand/60 mb-1">Note <span class="normal-case">(optional)</span></label>
                    <input type="text" id="link-note" name="note" maxlength="2000" class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo" placeholder="Context for this link" />
                </div>
                @error('to_thought_id')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @enderror
                @error('link_type')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @enderror
                @error('note')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @enderror
                <button type="submit" class="rounded-lg bg-memory-violet text-white text-sm font-medium px-4 py-2 hover:opacity-90">Add link</button>
            </form>
        @else
            <p class="text-sm text-slate-brand/70">No other thoughts to link yet.</p>
        @endif
            </div>
        </details>
    </section>
</div>
