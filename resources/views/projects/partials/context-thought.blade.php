@php
    $editable = $editable ?? true;
    $contextLabel = $contextLabel ?? 'Project context';
@endphp
<section class="rounded-2xl border-2 border-neural-teal/35 bg-gradient-to-br from-neural-teal/8 via-white/90 to-memory-violet/5 backdrop-blur p-5 mb-8 shadow-[0_4px_24px_rgba(42,140,140,0.12)]">
    <div class="flex items-start justify-between gap-3 mb-4">
        <div class="flex items-center gap-2 min-w-0">
            <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-neural-teal/15 text-neural-teal" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4">
                    <path fill-rule="evenodd" d="m9.687 3.094-3.24 7.055a1 1 0 0 1-.884.576H3.862a1 1 0 0 0-.927 1.387l1.518 3.593a1 1 0 0 0 .927.62h2.701a1 1 0 0 1 .884.576l3.24 7.055a1 1 0 0 0 1.838 0l3.24-7.055a1 1 0 0 1 .884-.576h2.701a1 1 0 0 0 .927-.62l1.518-3.593A1 1 0 0 0 18.138 11H14.44a1 1 0 0 1-.884-.576l-3.24-7.055a1 1 0 0 0-1.838 0ZM10 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd" />
                </svg>
            </span>
            <div class="min-w-0">
                <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-neural-teal">{{ $contextLabel }}</h2>
                <p class="text-xs text-slate-brand/70 mt-0.5">Pinned briefing for this project and agents.</p>
            </div>
        </div>
        @if ($editable)
            <form method="POST" action="{{ route('projects.context.destroy', $project) }}" class="shrink-0">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-xs font-medium text-slate-brand hover:text-red-600">Unpin</button>
            </form>
        @endif
    </div>

    <a href="{{ $contextThoughtUrl ?? $contextThought->ideaTubViewUrl() }}" class="group block rounded-xl border border-neural-teal/20 bg-white/80 px-4 py-3 hover:border-neural-teal/40 hover:bg-white transition-colors">
        @if ($contextThought->isMicrositeDocumentLayout())
            <p class="text-sm font-medium text-deep-indigo group-hover:text-neural-teal">
                {{ \App\Support\Research\MicrositePageLabel::forThought($contextThought) }}
            </p>
        @else
            <div class="prose prose-sm max-w-none text-deep-indigo line-clamp-6">
                <x-safe-markdown :markdown="$contextThought->content" />
            </div>
        @endif
        <p class="mt-2 text-xs font-medium text-neural-teal group-hover:underline">Open full thought</p>
    </a>
</section>
