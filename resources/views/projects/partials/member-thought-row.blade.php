@php
    $presenter = \App\View\Presenters\Projects\ProjectMemberThoughtPresenter::fromThought($thought);
@endphp
<li class="group relative">
    <div class="flex items-start gap-4 py-4 sm:py-5">
        <a
            href="{{ $presenter->url() }}"
            class="min-w-0 flex-1 rounded-xl -mx-2 px-2 py-1 transition hover:bg-memory-violet/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/40"
        >
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-1.5">
                @if ($presenter->typeLabel())
                    <span class="inline-flex items-center rounded-md bg-memory-violet/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-memory-violet">
                        {{ $presenter->typeLabel() }}
                    </span>
                @endif
                <span class="text-xs text-slate-brand/55">{{ $presenter->updatedAtHuman() }}</span>
            </div>
            <p class="text-[15px] font-semibold leading-snug text-deep-indigo group-hover:text-memory-violet transition-colors">
                {{ $presenter->title() }}
            </p>
            @if ($presenter->excerpt())
                <p class="mt-1.5 text-sm leading-relaxed text-slate-brand/75 line-clamp-2">
                    {{ $presenter->excerpt() }}
                </p>
            @endif
        </a>

        @if (! app(\App\Services\DemoMode::class)->enabled())
        <div class="flex shrink-0 items-center gap-1 pt-1 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100 transition-opacity">
            <form method="POST" action="{{ route('projects.context.store', $project) }}">
                @csrf
                <input type="hidden" name="thought_id" value="{{ $thought->id }}">
                <button
                    type="submit"
                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-neural-teal hover:bg-neural-teal/10"
                    title="Pin as project context"
                >
                    Pin as context
                </button>
            </form>
            <form method="POST" action="{{ route('projects.thoughts.destroy', [$project, $thought]) }}" onsubmit="return confirm('Remove this thought from the project?');">
                @csrf
                @method('DELETE')
                <button
                    type="submit"
                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-brand/70 hover:bg-red-50 hover:text-red-600"
                >
                    Remove
                </button>
            </form>
        </div>
        @endif
    </div>
</li>
