{{--
  Expects:
    $root_html, $sections (Collection of objects with ->id, ->thought (Thought), ->content_html)
  Optional (inherited from parent view):
    $commentsPresenter (App\View\Presenters\Comments\ResearchCommentsPresenter)
    $commentsMode ('owner' | 'guest', default 'owner')
    $commentsFormAction (string, default route('comments.store'))
    $commentsShowControls (bool, default true)
    Any of these may be omitted when rendering without comments (e.g. demo/preview contexts).
--}}
@php
    $hasComments = isset($commentsPresenter);
    $commentsMode = $commentsMode ?? 'owner';
    $commentsFormAction = $commentsFormAction ?? ($hasComments ? route('comments.store') : null);
    $commentsShowControls = $commentsShowControls ?? true;
@endphp

<div class="@if($hasComments && $sections->isNotEmpty()) lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-10 @endif">
    <div>
        <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[14px] md:text-[15px]">
            {!! $root_html !!}
        </div>
        @stack('research-after-root')
        @if($sections->isNotEmpty())
            <ul class="mt-8 space-y-8 border-t border-memory-violet/10 pt-8 list-none pl-0">
                @foreach($sections as $section)
                    @php($sectionId = $section->id ?? null)
                    <li @if($sectionId) id="section-{{ $sectionId }}" @endif>
                        <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-slate-brand prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[13px] md:text-[14px]">
                            {!! $section->content_html !!}
                        </div>
                        @if($hasComments && isset($section->thought))
                            @php
                                $sectionRows = $commentsPresenter->sectionRowsFor($section->thought);
                                $sectionAllowed = $commentsPresenter->canCommentOnSection($section->thought);
                            @endphp
                            <details class="mt-3 lg:hidden">
                                <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wider text-memory-violet/80">
                                    {{ count($sectionRows) }} {{ \Illuminate\Support\Str::plural('comment', count($sectionRows)) }}
                                </summary>
                                <div class="mt-3">
                                    @include('comments._thread', [
                                        'rows' => $sectionRows,
                                        'formAction' => $commentsFormAction,
                                        'commentableType' => 'thought',
                                        'commentableId' => $section->thought->id,
                                        'mode' => $commentsMode,
                                        'disabledMessage' => $sectionAllowed ? null : 'Comments are disabled.',
                                        'title' => 'Section comments',
                                        'showControls' => $commentsShowControls,
                                    ])
                                </div>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @if($hasComments && $sections->isNotEmpty())
        <aside class="hidden lg:block">
            <div class="space-y-6 sticky top-6">
                @foreach($sections as $section)
                    @if(isset($section->thought))
                        @php
                            $sectionRows = $commentsPresenter->sectionRowsFor($section->thought);
                            $sectionAllowed = $commentsPresenter->canCommentOnSection($section->thought);
                        @endphp
                        <div data-section-anchor="{{ $section->thought->id }}">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-memory-violet/80 mb-2">
                                Section {{ $loop->iteration }}
                            </p>
                            @include('comments._thread', [
                                'rows' => $sectionRows,
                                'formAction' => $commentsFormAction,
                                'commentableType' => 'thought',
                                'commentableId' => $section->thought->id,
                                'mode' => $commentsMode,
                                'disabledMessage' => $sectionAllowed ? null : 'Comments are disabled.',
                                'title' => 'Comments',
                                'showControls' => $commentsShowControls,
                            ])
                        </div>
                    @endif
                @endforeach
            </div>
        </aside>
    @endif
</div>
