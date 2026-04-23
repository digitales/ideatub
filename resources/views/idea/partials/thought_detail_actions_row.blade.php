@php
    $showShare = isset($thoughtDetail) && $thoughtDetail->showDocumentShareBlock();
    $showAdd = $editable ?? true;
    $isIdeaDetailThought = ($thought->metadata['type'] ?? null) === 'idea';
    $importedIdeaDetailResearchAck = $isIdeaDetailThought && data_get($thought->source_metadata, 'provenance') === 'upload';
@endphp
<div class="mt-4 pt-4 border-t border-memory-violet/10">
    <div class="flex flex-wrap items-start gap-x-6 gap-y-3">
        @if ($showShare)
            <div class="min-w-0">
                @include('idea.partials.thought_detail_document_share_links', ['thoughtDetail' => $thoughtDetail])
            </div>

            <div
                class="hidden h-auto min-h-[2.25rem] w-px shrink-0 self-stretch bg-memory-violet/20 sm:block"
                role="presentation"
                aria-hidden="true"
            ></div>
        @endif

        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Actions</p>
            <div class="flex flex-col gap-y-2 text-[12px]">
                @if ($showAdd)
                    @include('idea.partials.thought_detail_add_to_project', [
                        'thought' => $thought,
                        'projectsToAttachToThought' => $projectsToAttachToThought ?? collect(),
                        'inActionsRow' => true,
                    ])
                @endif

                @include('idea.partials.thought_detail_linked_thoughts_block', [
                    'thought' => $thought,
                    'thoughtOutgoingLinks' => $thoughtOutgoingLinks ?? collect(),
                    'thoughtIncomingLinks' => $thoughtIncomingLinks ?? collect(),
                    'linkTargetThoughtOptions' => $linkTargetThoughtOptions ?? collect(),
                    'linkTargetThoughtOptionsUsedGlobalFallback' => $linkTargetThoughtOptionsUsedGlobalFallback ?? false,
                    'inActionsRow' => true,
                ])
                @if ($isIdeaDetailThought && ($editable ?? true))
                    <form
                        method="POST"
                        action="{{ route('ideas.research', $thought) }}"
                        class="mt-2"
                        @if ($importedIdeaDetailResearchAck)
                            onsubmit="if (this.dataset.ackDone!=='1'){event.preventDefault();if(confirm('This idea was imported from a file. Research will send its text to the AI. Continue?')){this.dataset.ackDone='1';var h=document.createElement('input');h.type='hidden';h.name='provenance_ack';h.value='1';this.appendChild(h);if(typeof this.requestSubmit==='function'){this.requestSubmit();}else{this.submit();}}return false;}"
                        @endif
                    >
                        @csrf
                        <button type="submit" class="text-xs font-medium text-neural-teal hover:underline">
                            Run research for this idea
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
