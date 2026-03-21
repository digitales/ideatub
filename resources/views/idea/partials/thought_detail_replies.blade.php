<section class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80">Replies</p>

    @if ($thought->comments->isNotEmpty())
        <ul class="mt-4 space-y-3">
            @foreach ($thought->comments as $comment)
                <li class="rounded-xl border border-memory-violet/15 bg-white/70 px-4 py-3">
                    <p class="text-[13px] text-slate-brand leading-relaxed whitespace-pre-line">{{ $comment->content }}</p>
                    <p class="text-[10px] text-slate-brand/40 mt-1">{{ $comment->created_at->diffForHumans() }}</p>
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-4 text-sm text-slate-brand/50">No replies yet.</p>
    @endif

    <form method="POST" action="{{ route('thoughts.store') }}" class="mt-5 space-y-3">
        @csrf
        <input type="hidden" name="parent_id" value="{{ $thought->id }}">
        <textarea
            name="content"
            rows="3"
            placeholder="Add a reply"
            class="w-full rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3 text-sm text-deep-indigo placeholder:text-slate-brand/40 focus:border-memory-violet/40 focus:outline-none focus:ring-2 focus:ring-memory-violet/20"
        ></textarea>
        <div class="flex justify-end">
            <button
                type="submit"
                class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
            >
                Reply
            </button>
        </div>
    </form>
</section>
