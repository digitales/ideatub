@php
    $showEmailResearch = ($thought->source ?? null) === 'email';
    $nr = $showEmailResearch ? data_get($thought->source_metadata, 'newsletter_research') : null;
    $researchStatus = is_array($nr) ? ($nr['status'] ?? null) : null;
    $researchThoughtId = is_array($nr) ? ($nr['research_thought_id'] ?? null) : null;
@endphp
@if ($showEmailResearch && is_string($researchStatus) && $researchStatus !== '')
    @php
        $labels = [
            'research_queued' => 'Research queued',
            'research_completed' => 'Research ready',
            'research_partial' => 'Partial research',
            'research_skipped' => 'Research skipped',
            'research_failed' => 'Research failed',
        ];
        $label = $labels[$researchStatus] ?? ucfirst(str_replace('_', ' ', $researchStatus));
        $showResearchLink = is_string($researchThoughtId) && $researchThoughtId !== ''
            && in_array($researchStatus, ['research_completed', 'research_partial'], true);
    @endphp
    <span
        class="inline-flex items-center rounded-md border border-memory-violet/20 bg-memory-violet/5 px-1.5 py-0.5 text-[10px] font-medium text-memory-violet/80"
        data-email-research-status="{{ $researchStatus }}"
    >{{ $label }}</span>
    @if ($showResearchLink)
        <a href="{{ route('idea.research.show', $researchThoughtId) }}" class="text-[10.5px] font-medium text-memory-violet hover:underline">View research</a>
    @endif
@endif
