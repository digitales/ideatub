{{--
  Expects:
    $researchPreview — array with root_html, section_html_chunks, full_research_url, optional tags
    $researchPreviewSections — \Illuminate\Support\Collection (from ThoughtDetailPresenter)
    $researchPreviewTagList — list<string> (from ThoughtDetailPresenter::researchPreviewTagList)
    $researchContentComments — \App\View\Research\ResearchContentCommentsViewData
--}}
<article class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Research preview</p>
    @include('idea.partials.research_content', [
        'root_html' => $researchPreview['root_html'],
        'sections' => $researchPreviewSections,
        'researchContentComments' => $researchContentComments,
    ])
    @if ($researchPreviewTagList !== [])
        <div class="mt-6 flex flex-wrap items-center gap-x-2 gap-y-1.5">
            <span class="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-brand/55">Research tags</span>
            <span class="flex flex-wrap gap-1.5">
                @foreach ($researchPreviewTagList as $tag)
                    @if (trim((string) $tag) !== '')
                        <a
                            href="{{ route('idea.stream', ['tag' => \App\Support\TagSlug::from((string) $tag)]) }}"
                            class="text-[10px] font-medium px-2 py-0.5 rounded-full bg-memory-violet/10 text-memory-violet hover:opacity-90"
                        >#{{ $tag }}</a>
                    @endif
                @endforeach
            </span>
        </div>
    @endif
    <p class="mt-6 pt-4 border-t border-memory-violet/10">
        <a href="{{ $researchPreview['full_research_url'] }}" class="text-[13px] font-medium text-memory-violet hover:underline">View full research</a>
    </p>
</article>
