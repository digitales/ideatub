@if ($thoughtProjectsForDetail->isNotEmpty())
    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <section>
            <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Projects</h2>
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
        </section>
    </div>
@endif
