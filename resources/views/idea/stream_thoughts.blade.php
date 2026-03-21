@php
    $shareByThoughtId = $shareByThoughtId ?? collect();
@endphp
@foreach ($thoughts as $thought)
    @php
        $share = $shareByThoughtId[$thought->id] ?? null;
    @endphp
    <div data-thought-id="{{ $thought->id }}" class="relative rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3.5 mb-2 hover:border-memory-violet/20 hover:shadow-[0_2px_12px_rgba(109,106,247,0.08)] transition-all">
        <div class="absolute top-3 right-3">
            @include('idea.partials.thought_card_actions', ['thought' => $thought, 'editable' => auth()->check() && auth()->id() === $thought->user_id, 'share' => $share])
        </div>
        <div class="pr-8">
            <a
                href="{{ route('thoughts.show', $thought) }}"
                class="block rounded-lg -mx-1 px-1 py-0.5 hover:bg-memory-violet/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/40"
            >
                <p class="text-[13.5px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line">{{ $thought->content }}</p>
            </a>

            <div class="flex items-center gap-2 flex-wrap mt-2">
                @if($thought->relationLoaded('comments') && $thought->comments->isNotEmpty())
                    <a href="{{ route('idea.research.show', $thought) }}" class="text-[10.5px] font-medium text-memory-violet hover:underline">View formatted</a>
                @endif
                @php
                    $activityAt = null;
                    if (($thought->source ?? null) === 'jira') {
                        $jiraUpdatedAt = data_get($thought->source_metadata, 'jira_updated_at');
                        if (is_string($jiraUpdatedAt) && trim($jiraUpdatedAt) !== '') {
                            try {
                                $activityAt = \Carbon\Carbon::parse($jiraUpdatedAt);
                            } catch (\Throwable) {
                                $activityAt = null;
                            }
                        }
                    }
                @endphp
                <span class="text-[10.5px] text-slate-brand/40">{{ ($activityAt ?? $thought->created_at)->diffForHumans() }}</span>
                @if ($thought->source)
                    <span class="text-[10.5px] text-slate-brand/40">{{ ucfirst(strtolower($thought->source)) }}</span>
                @endif
                @include('idea.partials.thought_tag_row', ['thought' => $thought, 'editable' => true])
            </div>

            @if ($thought->relationLoaded('comments') && $thought->comments->isNotEmpty())
                <a
                    href="{{ route('thoughts.show', $thought) }}"
                    class="block rounded-lg -mx-1 px-1 py-0.5 mt-2 hover:bg-memory-violet/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/40"
                >
                    <ul class="ml-3 pl-3 border-l border-memory-violet/15 space-y-2">
                        @foreach ($thought->comments as $comment)
                            <li>
                                <p class="text-[12.5px] text-slate-brand leading-relaxed whitespace-pre-line">{{ $showFullSections ?? false ? $comment->content : Str::limit($comment->content, 200) }}</p>
                                <p class="text-[10px] text-slate-brand/40 mt-0.5">{{ $comment->created_at->diffForHumans() }}</p>
                            </li>
                        @endforeach
                    </ul>
                </a>
            @endif
        </div>
    </div>
@endforeach
