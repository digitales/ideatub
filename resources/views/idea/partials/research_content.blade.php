{{--
  Expects:
    $root_html (string)
    $sections (\Illuminate\Support\Collection) — objects with ->id, optional ->thought, ->content_html
    $researchContentComments (\App\View\Research\ResearchContentCommentsViewData) — use ::none() when there is no comment UI
--}}
<div class="@if ($researchContentComments->hasComments && $sections->isNotEmpty()) lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-10 @endif">
    <div>
        <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[14px] md:text-[15px]">
            {!! $root_html !!}
        </div>
        @stack('research-after-root')
        @if($sections->isNotEmpty())
            <ul class="mt-8 space-y-8 border-t border-memory-violet/10 pt-8 list-none pl-0">
                @if($researchContentComments->hasComments)
                    @foreach($researchContentComments->sectionItems as $item)
                        <li @if($item->id) id="section-{{ $item->id }}" @endif>
                            <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-slate-brand prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[13px] md:text-[14px]">
                                {!! $item->contentHtml !!}
                            </div>
                            @if($item->thought && $item->mobileThreadInclude)
                                <details class="mt-3 lg:hidden">
                                    <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wider text-memory-violet/80">
                                        {{ $item->mobileSummary['count'] }} {{ $item->mobileSummary['label'] }}
                                    </summary>
                                    <div class="mt-3">
                                        @include('comments._thread', $item->mobileThreadInclude)
                                    </div>
                                </details>
                            @endif
                        </li>
                    @endforeach
                @else
                    @foreach($sections as $section)
                        <li @if(isset($section->id)) id="section-{{ $section->id }}" @endif>
                            <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-slate-brand prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[13px] md:text-[14px]">
                                {!! $section->content_html !!}
                            </div>
                        </li>
                    @endforeach
                @endif
            </ul>
        @endif
    </div>
    @if($researchContentComments->hasComments && $sections->isNotEmpty())
        <aside class="hidden lg:block">
            <div class="space-y-6 sticky top-6">
                @foreach($researchContentComments->sectionItems as $item)
                    @if($item->thought && $item->sidebarThreadInclude)
                        <div data-section-anchor="{{ $item->thought->id }}">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-memory-violet/80 mb-2">
                                Section {{ $loop->iteration }}
                            </p>
                            @include('comments._thread', $item->sidebarThreadInclude)
                        </div>
                    @endif
                @endforeach
            </div>
        </aside>
    @endif
</div>
