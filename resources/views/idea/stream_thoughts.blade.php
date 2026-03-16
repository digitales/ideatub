@php
    $tagColors = ['violet', 'teal', 'indigo'];
    $tagMap = [
        'violet' => 'bg-memory-violet/10 text-memory-violet',
        'teal'   => 'bg-neural-teal/10 text-neural-teal',
        'indigo' => 'bg-deep-indigo/8 text-slate-brand',
    ];
@endphp
@foreach ($thoughts as $thought)
    @php
        $tags = $thought->metadata['tags'] ?? [];
    @endphp
    <div class="rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3.5 mb-2 hover:border-memory-violet/20 hover:shadow-[0_2px_12px_rgba(109,106,247,0.08)] transition-all">
        <p class="text-[13.5px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line">{{ $thought->content }}</p>
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
        </div>
        @if ($thought->relationLoaded('comments') && $thought->comments->isNotEmpty())
            <ul class="mt-3 ml-3 pl-3 border-l border-memory-violet/15 space-y-2">
                @foreach ($thought->comments as $comment)
                    <li>
                        <p class="text-[12.5px] text-slate-brand leading-relaxed whitespace-pre-line">{{ $showFullSections ?? false ? $comment->content : Str::limit($comment->content, 200) }}</p>
                        <p class="text-[10px] text-slate-brand/40 mt-0.5">{{ $comment->created_at->diffForHumans() }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endforeach
