@extends('layouts.idea')

@section('title', $query ? 'Search — IdeaTub' : 'IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">

    {{-- Flash messages --}}
    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-600">
            {{ session('error') }}
        </div>
    @endif

    {{-- Hero --}}
    <p class="text-center text-[11px] font-semibold tracking-[0.12em] uppercase text-memory-violet mb-2.5">Your thinking space</p>
    <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-1.5">A calm archive for your ideas</h1>
    <p class="text-center text-sm text-slate-brand mb-9">Capture thoughts before they disappear.</p>

    {{-- Capture box (initial content in data attr to avoid @json breaking the x-data attribute) --}}
    @php
        $initialContent = (old('content') === '[object HTMLTextAreaElement]' ? '' : old('content', ''));
    @endphp
    <div
        x-data="captureBox()"
        data-initial-content="{{ e($initialContent) }}"
        data-focus-reply="{{ (isset($replyingTo) && $replyingTo) ? '1' : '0' }}"
        @focus-capture.window="focusCapture()"
        class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-4 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-3 transition-shadow focus-within:shadow-[0_4px_32px_rgba(109,106,247,0.16)] focus-within:border-memory-violet/50"
    >
        <form
            method="POST"
            action="{{ route('thoughts.store') }}"
            @keydown.meta.enter.prevent="$el.submit()"
        >
            @csrf
            <input type="hidden" name="parent_id" value="{{ isset($replyingTo) && $replyingTo ? $replyingTo->id : '' }}">

            @if (isset($replyingTo) && $replyingTo)
                <div class="flex items-start gap-2 mb-3 px-3 py-2 rounded-lg border border-memory-violet/15 bg-memory-violet/5">
                    <div class="flex-1 min-w-0">
                        <span class="text-[11px] font-semibold uppercase tracking-wider text-memory-violet/80">Replying to</span>
                        <p class="text-sm text-deep-indigo mt-0.5 line-clamp-2">{{ e(Str::limit($replyingTo->content, 80)) }}</p>
                    </div>
                    <a href="{{ route('idea.index') }}" class="shrink-0 text-xs font-medium text-slate-brand hover:text-deep-indigo px-2.5 py-1.5 rounded-md border border-slate-200 hover:border-slate-300 bg-white/80 transition-colors" title="Cancel reply">Cancel</a>
                </div>
            @endif

            <textarea
                name="content"
                id="content"
                rows="3"
                required
                x-ref="captureTextarea"
                x-model="content"
                @if($errors->has('content')) aria-describedby="content-error" aria-invalid="true" @endif
                placeholder="What are you thinking?"
                class="w-full bg-transparent border-none outline-none resize-none text-sm text-deep-indigo placeholder-slate-brand/40 leading-relaxed"
            ></textarea>

            @error('content')
                <p id="content-error" class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror

            <div class="flex items-center justify-between mt-2.5 pt-2.5 border-t border-memory-violet/8">
                <span class="text-[11px] text-slate-brand/40">⌘ + Enter to store · ⌘/ to focus</span>
                <button
                    type="submit"
                    class="text-xs font-medium text-white px-4 py-1.5 rounded-lg transition-opacity hover:opacity-90"
                    style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                >
                    Store thought
                </button>
            </div>
        </form>
    </div>

    {{-- Thoughts list --}}
    <div
        x-data="{
            selectedThoughtIndex: 0,
            handleThoughtNav(detail) {
                if (!detail || !detail.direction) return;
                const cards = this.$el.querySelectorAll('[data-reply-href]');
                const max = Math.max(0, cards.length - 1);
                if (detail.direction === 'next') this.selectedThoughtIndex = Math.min(this.selectedThoughtIndex + 1, max);
                else this.selectedThoughtIndex = Math.max(this.selectedThoughtIndex - 1, 0);
                const card = cards[this.selectedThoughtIndex];
                if (card) card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            },
            handleThoughtReply() {
                const cards = this.$el.querySelectorAll('[data-reply-href]');
                const card = cards[this.selectedThoughtIndex];
                const href = card?.dataset?.replyHref;
                if (href) window.location.href = href;
            }
        }"
        @thought-nav.window="handleThoughtNav($event.detail)"
        @thought-reply.window="handleThoughtReply()"
    >
    <div class="flex items-center justify-between mt-9 mb-3.5">
        <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-slate-brand/50">
            @if ($query)
                Results for "{{ e($query) }}"
            @else
                Recent thoughts
            @endif
        </span>
        <span class="text-[11px] text-slate-brand/30">{{ $thoughts instanceof \Illuminate\Pagination\LengthAwarePaginator ? $thoughts->total() : count($thoughts) }} stored</span>
    </div>
    @if (!$thoughts->isEmpty())
        <p class="text-[11px] text-slate-brand/40 mb-2">j / k to move · Enter to reply</p>
    @endif

    @php $replyableIndex = -1; @endphp
    @forelse ($thoughts as $thought)
        @php
            if (!$thought->parent_id) {
                $replyableIndex++;
            }
            $tags = $thought->metadata['tags'] ?? [];
            $tagColors = ['violet', 'teal', 'indigo'];
            $tagMap = [
                'violet' => 'bg-memory-violet/10 text-memory-violet',
                'teal'   => 'bg-neural-teal/10 text-neural-teal',
                'indigo' => 'bg-deep-indigo/8 text-slate-brand',
            ];
            $replyHref = !$thought->parent_id ? route('idea.index', ['parent_id' => $thought->id]) : '';
        @endphp

        <div
            data-index="{{ $loop->index }}"
            data-reply-href="{{ $replyHref }}"
            :class="{ 'ring-2 ring-memory-violet ring-offset-2': selectedThoughtIndex === {{ $thought->parent_id ? -1 : $replyableIndex }} }"
            class="rounded-xl border border-memory-violet/10 bg-white/68 backdrop-blur px-4 py-3.5 mb-2 hover:bg-white/90 hover:border-memory-violet/20 hover:shadow-[0_2px_12px_rgba(109,106,247,0.08)] transition-all cursor-pointer"
        >

            @if ($thought->parent_id && $thought->relationLoaded('parent') && $thought->parent)
                <p class="text-[11px] text-slate-brand/50 mb-1">
                    Comment on: {{ Str::limit($thought->parent->content, 80) }}
                </p>
            @endif

            <p class="text-[13.5px] text-deep-indigo leading-relaxed mb-2">{{ e($thought->content) }}</p>

            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-[10.5px] text-slate-brand/40">{{ $thought->created_at->diffForHumans() }}</span>

                @foreach ($tags as $i => $tag)
                    <span class="text-[10px] font-medium px-2 py-0.5 rounded-full {{ $tagMap[$tagColors[$i % 3]] }}">
                        #{{ $tag }}
                    </span>
                @endforeach

                @if (!$thought->parent_id)
                    <a href="{{ route('idea.index', ['parent_id' => $thought->id]) }}"
                       class="text-[10.5px] text-memory-violet/60 hover:text-memory-violet transition-colors ml-auto">
                        Reply
                    </a>
                @endif
            </div>

            {{-- Nested comments --}}
            @if ($thought->relationLoaded('comments') && $thought->comments->isNotEmpty())
                <ul class="mt-3 ml-3 pl-3 border-l border-memory-violet/15 space-y-2">
                    @foreach ($thought->comments as $comment)
                        <li>
                            <p class="text-[12.5px] text-slate-brand leading-relaxed">{{ e(Str::limit($comment->content, 200)) }}</p>
                            <p class="text-[10px] text-slate-brand/40 mt-0.5">{{ $comment->created_at->diffForHumans() }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

    @empty
        <div class="rounded-xl border border-memory-violet/10 bg-white/50 px-4 py-8 text-center text-sm text-slate-brand/50">
            @if ($query)
                No thoughts match your search. Try different words or capture a new one above.
            @else
                No thoughts yet. What are you thinking?
            @endif
        </div>
    @endforelse

    {{-- Pagination / load more --}}
    @if ($thoughts instanceof \Illuminate\Pagination\LengthAwarePaginator && $thoughts->hasMorePages())
        <div class="text-center pt-4">
            {{ $thoughts->links() }}
        </div>
    @endif
    </div>

</div>
@endsection
