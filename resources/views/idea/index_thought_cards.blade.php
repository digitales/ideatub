@php
    $replyableIndex = (int) ($replyableIndexStart ?? 0);
@endphp
@foreach ($thoughts as $thought)
    @php
        if (!$thought->parent_id) {
            $currentReplyableIndex = $replyableIndex;
            $replyableIndex++;
        } else {
            $currentReplyableIndex = -1;
        }
        $replyHref = !$thought->parent_id ? route('idea.index', ['parent_id' => $thought->id]) : '';
    @endphp

    <div
        data-thought-id="{{ $thought->id }}"
        data-index="{{ $loop->index }}"
        data-reply-href="{{ $replyHref }}"
        :class="{ 'ring-2 ring-memory-violet ring-offset-2': selectedThoughtIndex === {{ $currentReplyableIndex }} }"
        class="relative rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3.5 mb-2 hover:border-memory-violet/20 hover:shadow-[0_2px_12px_rgba(109,106,247,0.08)] transition-all"
    >
        <div class="absolute top-3 right-3">
            @include('idea.partials.thought_card_actions', ['thought' => $thought, 'editable' => auth()->check() && auth()->id() === $thought->user_id])
        </div>

        <div class="pr-8 min-w-0">
            @if ($thought->parent_id && $thought->relationLoaded('parent') && $thought->parent)
                <p class="text-[11px] text-slate-brand/50 mb-1 break-words [overflow-wrap:anywhere]">
                    Comment on: {{ Str::limit($thought->parent->content, 80) }}
                </p>
            @endif
            @include('idea.partials.editable_thought_content', [
                'thought' => $thought,
                'editable' => auth()->check() && auth()->id() === $thought->user_id,
                'displayClass' => 'text-[13.5px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line',
                'viewHref' => route('thoughts.show', $thought),
                'viewLinkClass' => 'block rounded-lg -mx-1 px-1 py-0.5 hover:bg-memory-violet/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/40',
                'previewMode' => true,
            ])

            @if ($thought->relationLoaded('comments') && $thought->comments->isNotEmpty())
                <a
                    href="{{ route('thoughts.show', $thought) }}"
                    class="block rounded-lg -mx-1 px-1 py-0.5 hover:bg-memory-violet/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/40"
                >
                    <ul class="comments-list mt-3 ml-3 pl-3 border-l border-memory-violet/15 space-y-2" data-comments-list>
                        @foreach ($thought->comments as $comment)
                            <li>
                                <p class="text-[12.5px] text-slate-brand leading-relaxed whitespace-pre-line break-words [overflow-wrap:anywhere]">{{ Str::limit($comment->content, 200) }}</p>
                                <p class="text-[10px] text-slate-brand/40 mt-0.5">{{ $comment->created_at->diffForHumans() }}</p>
                            </li>
                        @endforeach
                    </ul>
                </a>
            @elseif(!$thought->parent_id)
                <ul class="comments-list mt-3 ml-3 pl-3 border-l border-memory-violet/15 space-y-2 hidden" data-comments-list aria-hidden="true"></ul>
            @endif

            <div class="mt-2 flex min-w-0 items-center gap-2 flex-wrap">
                <span class="text-[10.5px] text-slate-brand/40">{{ $thought->created_at->diffForHumans() }}</span>
                @include('idea.partials.thought_type_badge', ['thought' => $thought])
                @include('idea.partials.email_newsletter_research_status', ['thought' => $thought])
                @include('idea.partials.thought_tag_row', ['thought' => $thought, 'editable' => true])
                @if (!$thought->parent_id)
                    <a href="{{ route('idea.index', ['parent_id' => $thought->id]) }}"
                       class="text-[10.5px] text-memory-violet/60 hover:text-memory-violet transition-colors ml-auto">
                        Reply
                    </a>
                @endif
            </div>
        </div>
    </div>
@endforeach
