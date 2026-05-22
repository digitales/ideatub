@php
    $thought = $thoughtDetail->thought();
@endphp
<p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Share</p>
@include('idea.partials.document_share_widget', [
    'thought' => $thought,
    'share' => $thoughtDetail->documentShare(),
    'returnTo' => route('thoughts.show', $thought),
    'placement' => 'detail',
])
