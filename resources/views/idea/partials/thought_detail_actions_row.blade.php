@php
    $showShare = isset($thoughtDetail) && $thoughtDetail->showDocumentShareBlock();
    $showAdd = $editable ?? true;
@endphp
@if ($showShare || $showAdd)
    <div class="mt-4 pt-4 border-t border-memory-violet/10">
        <div class="flex flex-wrap items-start gap-x-6 gap-y-3">
            @if ($showShare)
                <div class="min-w-0">
                    @include('idea.partials.thought_detail_document_share_links', ['thoughtDetail' => $thoughtDetail])
                </div>
            @endif
            @if ($showAdd)
                @include('idea.partials.thought_detail_add_to_project', [
                    'thought' => $thought,
                    'projectsToAttachToThought' => $projectsToAttachToThought ?? collect(),
                    'inActionsRow' => true,
                ])
            @endif
        </div>
    </div>
@endif
