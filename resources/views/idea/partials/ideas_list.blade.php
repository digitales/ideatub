{{-- Ideas list: header, list or empty state, pagination --}}
<div class="ideatub-surface-frosted mb-4 flex items-center justify-between gap-3 px-4 py-2.5">
    <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-slate-brand/60">Your ideas</span>
    <span class="text-xs text-slate-brand/50 tabular-nums">{{ $ideas->total() }} incomplete</span>
</div>

@if ($ideas->isEmpty())
    <div class="ideatub-surface-muted px-6 py-10 text-center text-base/7 sm:text-sm/6 text-slate-brand/60">
        No ideas yet. Add one above.
    </div>
@else
    <ul class="space-y-3" role="list">
        @foreach ($ideaRows as $row)
            @php
                $thought = $row->thought();
                $researchList = $row->researchList();
                $researchStatus = $row->researchStatus();
                $hasResearch = $researchList->isNotEmpty();
                $actionLabel = $hasResearch ? 'Regenerate' : 'Research this idea';
                $contentEditable = $row->contentEditable();
                $displayContent = $row->displayContent();
                $rawEditorContent = $contentEditable ? $thought->content : $displayContent;
                $importedIdeaResearchAck = data_get($thought->source_metadata, 'provenance') === 'upload';
            @endphp
            <li data-thought-id="{{ $thought->id }}" class="ideatub-surface relative px-4 py-4 transition hover:ring-memory-violet/20 dark:hover:ring-violet-400/30">
                <form method="POST" action="{{ route('ideas.toggle-completed', $thought) }}" class="absolute left-4 top-4 z-10">
                    @csrf
                    @method('PATCH')
                    <label class="cursor-pointer">
                        <input
                            type="checkbox"
                            class="rounded border-slate-300 text-neural-teal focus:ring-memory-violet/30"
                            onchange="this.form.submit()"
                        />
                        <span class="sr-only">Mark as complete</span>
                    </label>
                </form>
                <div class="min-w-0 pl-8">
                    <div class="absolute top-3 right-3 z-10">
                        @include('idea.partials.thought_card_actions', [
                            'thought' => $thought,
                            'editable' => $contentEditable,
                            'documentShareEligible' => $thought->isShareableDocumentRoot(),
                        ])
                    </div>
                    <div class="pr-8">
                        @include('idea.partials.editable_thought_content', [
                            'thought' => $thought,
                            'editable' => $contentEditable,
                            'displayContent' => $displayContent,
                            'rawEditorContent' => $rawEditorContent,
                            'displayClass' => 'text-base/7 sm:text-sm/6 text-deep-indigo whitespace-pre-line mb-0',
                            'previewMaxLength' => 200,
                            'previewMode' => true,
                            'previewLineClamp' => 'line-clamp-4',
                            'viewHref' => route('thoughts.show', $thought),
                            'viewLinkClass' => 'block rounded-lg -mx-1 px-1 py-0.5 hover:bg-memory-violet/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-memory-violet/40',
                        ])
                        <p class="text-[11px] text-slate-brand/50 mt-1.5">{{ $row->loggedDateYmd() }}</p>
                        @include('idea.partials.thought_tag_row', ['thought' => $thought, 'editable' => $contentEditable])
                        {{-- Research block --}}
                        <div class="mt-3 pt-3 border-t border-memory-violet/10">
                            @if ($researchStatus->showsInProgress())
                                <p class="text-xs text-slate-brand/70 flex items-center gap-1.5">
                                    <span class="inline-block size-3.5 rounded-full border-2 border-neural-teal/50 border-t-neural-teal animate-spin" aria-hidden="true"></span>
                                    <span>{{ $researchStatus->statusLine() }}</span>
                                </p>
                            @elseif ($researchStatus->showsFailed())
                                <p class="text-xs text-red-600/90 mb-2">
                                    Research failed
                                    @if ($researchStatus->failedSkillName())
                                        <span class="text-slate-brand/80">({{ $researchStatus->failedSkillName() }})</span>
                                    @endif
                                    @if ($researchStatus->failedSummary())
                                        <span class="text-slate-brand/70">— {{ Str::limit($researchStatus->failedSummary(), 80) }}</span>
                                    @endif
                                </p>
                            @endif

                            @if (! $researchStatus->showsInProgress())
                                <form
                                    method="POST"
                                    action="{{ route('ideas.research', $thought) }}"
                                    class="inline"
                                    @if ($importedIdeaResearchAck)
                                        onsubmit="if (this.dataset.ackDone!=='1'){event.preventDefault();if(confirm('This idea was imported from a file. Research will send its text to the AI. Continue?')){this.dataset.ackDone='1';var h=document.createElement('input');h.type='hidden';h.name='provenance_ack';h.value='1';this.appendChild(h);if(typeof this.requestSubmit==='function'){this.requestSubmit();}else{this.submit();}}return false;}"
                                    @endif
                                >
                                    @csrf
                                    <button type="submit" class="text-xs font-medium text-neural-teal hover:underline">
                                        {{ $actionLabel }}
                                    </button>
                                </form>
                            @endif

                            @if ($hasResearch)
                                <p class="text-[11px] font-semibold text-slate-brand/60 uppercase tracking-wide mb-1 mt-2">Research</p>
                                @foreach ($row->researchPreviewRows() as $researchRow)
                                    <div class="text-sm text-slate-brand/80 mb-2">
                                        <p>{{ $researchRow['preview'] }}</p>
                                        <a href="{{ route('idea.research.show', $researchRow['research']) . '?from=ideas' }}" class="text-xs font-medium text-neural-teal hover:underline">View formatted</a>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
    @if ($ideas->hasMorePages())
        <div class="mt-4 flex justify-center">
            {{ $ideas->links('pagination.idea') }}
        </div>
    @endif
@endif
