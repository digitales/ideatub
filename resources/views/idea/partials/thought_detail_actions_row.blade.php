@php
    $showShare = isset($thoughtDetail) && $thoughtDetail->showDocumentShareBlock();
    $showAdd = $editable ?? true;
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

        <div class="flex min-w-0 flex-1 flex-wrap items-start gap-x-5 gap-y-3">
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
        </div>
    </div>
</div>
