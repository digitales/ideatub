@php
    $replyableIndex = (int) ($replyableIndexStart ?? 0);
    $tagColors = ['violet', 'teal', 'indigo'];
    $tagMap = [
        'violet' => 'bg-memory-violet/10 text-memory-violet',
        'teal'   => 'bg-neural-teal/10 text-neural-teal',
        'indigo' => 'bg-deep-indigo/8 text-slate-brand',
    ];
@endphp
@foreach ($thoughts as $thought)
    @php
        if (!$thought->parent_id) {
            $currentReplyableIndex = $replyableIndex;
            $replyableIndex++;
        } else {
            $currentReplyableIndex = -1;
        }
        $tags = $thought->metadata['tags'] ?? [];
        $replyHref = !$thought->parent_id ? route('idea.index', ['parent_id' => $thought->id]) : '';
    @endphp

    <div
        data-thought-id="{{ $thought->id }}"
        data-index="{{ $loop->index }}"
        data-reply-href="{{ $replyHref }}"
        :class="{ 'ring-2 ring-memory-violet ring-offset-2': selectedThoughtIndex === {{ $currentReplyableIndex }} }"
        class="rounded-xl border border-memory-violet/10 bg-white/68 backdrop-blur px-4 py-3.5 mb-2 hover:bg-white/90 hover:border-memory-violet/20 hover:shadow-[0_2px_12px_rgba(109,106,247,0.08)] transition-all cursor-pointer"
    >

        @if ($thought->parent_id && $thought->relationLoaded('parent') && $thought->parent)
            <p class="text-[11px] text-slate-brand/50 mb-1">
                Comment on: {{ e(Str::limit($thought->parent->getDecodedContent(), 80)) }}
            </p>
        @endif

        <p class="text-[13.5px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line">{{ e($thought->getDecodedContent()) }}</p>

        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-[10.5px] text-slate-brand/40">{{ $thought->created_at->diffForHumans() }}</span>
            @if ($thought->source)
                <span class="text-[10.5px] text-slate-brand/40">{{ ucfirst(strtolower($thought->source)) }}</span>
            @endif

            @foreach ($tags as $i => $tag)
                <a href="{{ route('idea.stream', ['tag' => \Illuminate\Support\Str::slug($tag, '_')]) }}" class="text-[10px] font-medium px-2 py-0.5 rounded-full {{ $tagMap[$tagColors[$i % 3]] }} hover:opacity-90">
                    #{{ $tag }}
                </a>
            @endforeach

            @if (!$thought->parent_id)
                <a href="{{ route('idea.index', ['parent_id' => $thought->id]) }}"
                   class="text-[10.5px] text-memory-violet/60 hover:text-memory-violet transition-colors ml-auto">
                    Reply
                </a>
            @endif
        </div>

        @if ($thought->relationLoaded('comments') && $thought->comments->isNotEmpty())
            <ul class="comments-list mt-3 ml-3 pl-3 border-l border-memory-violet/15 space-y-2" data-comments-list>
                @foreach ($thought->comments as $comment)
                    <li>
                        <p class="text-[12.5px] text-slate-brand leading-relaxed whitespace-pre-line">{{ e(Str::limit($comment->getDecodedContent(), 200)) }}</p>
                        <p class="text-[10px] text-slate-brand/40 mt-0.5">{{ $comment->created_at->diffForHumans() }}</p>
                    </li>
                @endforeach
            </ul>
        @elseif(!$thought->parent_id)
            <ul class="comments-list mt-3 ml-3 pl-3 border-l border-memory-violet/15 space-y-2 hidden" data-comments-list aria-hidden="true"></ul>
        @endif
    </div>
@endforeach
