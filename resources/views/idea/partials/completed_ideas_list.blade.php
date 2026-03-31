@if ($ideas->isEmpty())
    <div class="rounded-xl border border-memory-violet/10 bg-white/50 px-4 py-12 text-center text-sm text-slate-brand/50">
        No completed ideas yet.
    </div>
@else
    <ul class="space-y-3">
        @foreach ($completedRows as $row)
            @php
                $thought = $row->thought();
            @endphp
            <li
                data-completed-idea-id="{{ $thought->id }}"
                class="rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3 hover:border-memory-violet/25 transition-colors"
            >
                <a href="{{ route('thoughts.show', $thought) }}" class="block min-w-0">
                    <p class="text-sm text-deep-indigo line-clamp-2">
                        {{ Str::limit($thought->content, 200) }}
                    </p>
                    <p class="text-[11px] text-slate-brand/50 mt-1">Logged {{ $row->loggedFormatted() }}</p>
                    <p class="text-[11px] text-slate-brand/50 mt-0.5">Completed {{ $row->completedFormatted() }}</p>
                </a>
            </li>
        @endforeach
    </ul>
    @if ($ideas->hasMorePages())
        <div class="mt-4 flex justify-center">
            {{ $ideas->links('pagination.idea') }}
        </div>
    @endif
@endif
