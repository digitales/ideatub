@php
    /** @var array{full_research_url: string, root_html: string, section_html_chunks: array<int, string>} $researchPreview */
    $researchPreviewSections = collect($researchPreview['section_html_chunks'] ?? [])->map(
        fn (string $html) => (object) ['content_html' => $html]
    );
@endphp
<article class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Research preview</p>
    @include('idea.partials.research_content', [
        'root_html' => $researchPreview['root_html'],
        'sections' => $researchPreviewSections,
    ])
    <p class="mt-6 pt-4 border-t border-memory-violet/10">
        <a href="{{ $researchPreview['full_research_url'] }}" class="text-[13px] font-medium text-memory-violet hover:underline">View full research</a>
    </p>
</article>
