@foreach ($cards as $card)
    <div
        data-thought-id="{{ $card->thought()->id }}"
        data-stream-card
        @if ($card->isVideoThought()) data-thought-kind="video" @endif
        class="ideatub-surface relative mb-3 px-4 py-4 transition hover:ring-memory-violet/20 dark:hover:ring-violet-400/30 @if ($card->isVideoThought()) border-l-[3px] border-l-rose-400/80 dark:border-l-rose-400/70 @endif"
    >
        <div class="absolute top-3 right-3">
            @include('idea.partials.thought_card_actions', [
                'thought' => $card->thought(),
                'editable' => $card->editable(),
                'share' => $card->share(),
                'documentShareEligible' => $card->documentShareEligible(),
            ])
        </div>
        <div class="pr-8 min-w-0">
            @include('idea.partials.editable_thought_content', [
                'thought' => $card->thought(),
                'editable' => $card->editable(),
                'displayContent' => $card->displayContent(),
                'rawEditorContent' => $card->editable() ? $card->thought()->content : $card->displayContent(),
                'displayClass' => 'text-[13.5px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line',
                'viewHref' => route('thoughts.show', $card->thought()),
                'viewLinkClass' => 'block rounded-lg -mx-1 px-1 py-0.5 hover:bg-memory-violet/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/40',
                'previewMode' => true,
            ])

            @if ($card->isVideoThought())
                <div class="mt-2 rounded-lg border border-rose-400/20 bg-rose-500/[0.06] px-3 py-2 space-y-1.5 text-[11px] leading-snug text-slate-brand">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-semibold uppercase tracking-wide text-rose-600/90">Video</span>
                        @if ($card->videoCanonicalUrl())
                            <span class="break-all text-slate-brand/80">{{ $card->videoCanonicalUrl() }}</span>
                        @endif
                        @if ($card->videoCanonicalHref())
                            <a
                                href="{{ $card->videoCanonicalHref() }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-medium text-memory-violet hover:underline"
                            >Open video</a>
                        @endif
                    </div>
                    @if ($card->transcriptStatusLabel())
                        <p class="text-slate-brand/90">{{ $card->transcriptStatusLabel() }}</p>
                    @endif
                    @if ($card->transcriptPresenceLabel())
                        <p class="text-slate-brand/70">{{ $card->transcriptPresenceLabel() }}</p>
                    @endif
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        @if ($card->videoLatestResearchUrl())
                            <a href="{{ $card->videoLatestResearchUrl() }}" class="font-medium text-memory-violet hover:underline">View research</a>
                        @endif
                        @if ($card->showFetchTranscriptAction())
                            <form method="POST" action="{{ route('videos.store') }}" class="inline">
                                @csrf
                                <input type="hidden" name="youtube_url" value="{{ $card->videoFetchTranscriptActionUrl() }}">
                                <button type="submit" class="font-medium text-memory-violet hover:underline">Fetch transcript</button>
                            </form>
                        @endif
                        @if ($card->showVideoResearchPending())
                            <span class="text-slate-brand/75">Research pending</span>
                        @endif
                        @if ($card->showVideoResearchNowHint())
                            <form method="POST" action="{{ route('videos.store') }}" class="inline">
                                @csrf
                                <input type="hidden" name="youtube_url" value="{{ $card->videoResearchActionUrl() }}">
                                <input type="hidden" name="research_now" value="1">
                                <button type="submit" class="font-medium text-memory-violet hover:underline">Research now</button>
                            </form>
                        @endif
                        @if ($card->showVideoRerunResearchHint())
                            <form method="POST" action="{{ route('videos.store') }}" class="inline">
                                @csrf
                                <input type="hidden" name="youtube_url" value="{{ $card->videoResearchActionUrl() }}">
                                <input type="hidden" name="research_now" value="1">
                                <button type="submit" class="font-medium text-memory-violet hover:underline">Rerun research</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

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

            <button
                type="button"
                class="stream-card-expand mt-2 text-[11px] font-medium text-memory-violet hover:underline relative z-10"
                onclick="var card=this.closest('[data-stream-card]');if(card.hasAttribute('data-expanded')){card.removeAttribute('data-expanded');this.textContent='Read more'}else{card.setAttribute('data-expanded','');this.textContent='Show less'}"
            >Read more</button>
        </div>
    </div>
@endforeach
