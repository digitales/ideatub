@php
    use App\Enums\ThoughtLinkType;

    $linkSectionOpen = $errors->has('to_thought_id') || $errors->has('link_type') || $errors->has('note');
    $inActionsRow = $inActionsRow ?? false;
    $linkedDetailsClass = $inActionsRow ? 'group w-full min-w-0' : 'group';
@endphp
<details class="{{ $linkedDetailsClass }}" @if ($linkSectionOpen) open @endif>
    <summary
        class="@if ($inActionsRow) cursor-pointer list-none font-medium text-memory-violet hover:underline select-none [&::-webkit-details-marker]:hidden @else cursor-pointer list-none [&::-webkit-details-marker]:hidden @endif"
    >
        @if ($inActionsRow)
            Linked thoughts
        @else
            <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80">Linked thoughts</span>
        @endif
    </summary>
    <div class="mt-3 w-full space-y-4 @if ($inActionsRow) min-w-[min(100%,24rem)] border-l border-memory-violet/15 pl-4 @endif">
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
