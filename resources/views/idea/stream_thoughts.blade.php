@foreach ($cards as $card)
    <div data-thought-id="{{ $card->thought()->id }}" class="relative rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3.5 mb-2 hover:border-memory-violet/20 hover:shadow-[0_2px_12px_rgba(109,106,247,0.08)] transition-all">
        <div class="absolute top-3 right-3">
            @include('idea.partials.thought_card_actions', ['thought' => $card->thought(), 'editable' => $card->editable(), 'share' => $card->share()])
        </div>
        <div class="pr-8 min-w-0">
            @include('idea.partials.editable_thought_content', [
                'thought' => $card->thought(),
                'editable' => $card->editable(),
                'displayContent' => $card->displayContent(),
                'displayClass' => 'text-[13.5px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line',
                'viewHref' => route('thoughts.show', $card->thought()),
                'viewLinkClass' => 'block rounded-lg -mx-1 px-1 py-0.5 hover:bg-memory-violet/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/40',
                'previewMode' => true,
            ])

            <div class="mt-2 flex min-w-0 items-center gap-2 flex-wrap">
                @if($card->showViewFormattedLink())
                    <a href="{{ route('idea.research.show', $card->thought()) }}" class="text-[10.5px] font-medium text-memory-violet hover:underline">View formatted</a>
                @endif
                <span class="text-[10.5px] text-slate-brand/40">{{ $card->activityAtHuman() }}</span>
                @include('idea.partials.thought_type_badge', ['thought' => $card->thought()])
                @include('idea.partials.email_newsletter_research_status', ['newsletterResearchStatus' => $card->newsletterResearchStatus()])
                @include('idea.partials.thought_tag_row', ['thought' => $card->thought(), 'editable' => $card->editable()])
            </div>

            @if ($card->showCommentsBlock())
                <a
                    href="{{ route('thoughts.show', $card->thought()) }}"
                    class="block rounded-lg -mx-1 px-1 py-0.5 mt-2 hover:bg-memory-violet/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/40"
                >
                    <ul class="ml-3 pl-3 border-l border-memory-violet/15 space-y-2">
                        @foreach ($card->commentPreviewRows() as $row)
                            <li>
                                <p class="text-[12.5px] text-slate-brand leading-relaxed whitespace-pre-line break-words [overflow-wrap:anywhere]">{{ $row['content'] }}</p>
                                <p class="text-[10px] text-slate-brand/40 mt-0.5">{{ $row['created_at_human'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </a>
            @endif
        </div>
    </div>
@endforeach
