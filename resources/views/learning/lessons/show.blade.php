@extends('layouts.idea')

@section('title', $lesson->title.' — '.$learningProject->title.' — Learn — IdeaTub')

@section('content')
<div class="max-w-[1100px] mx-auto px-6 pt-16 pb-24">
    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="text-xs font-semibold tracking-[0.14em] uppercase text-memory-violet/70 mb-2">Lesson</div>
            <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">{{ $lesson->title }}</h1>
            <div class="mt-2 text-xs text-slate-brand/70">
                slug: <span class="font-mono">{{ $lesson->slug }}</span>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('learn.projects.show', $learningProject) }}" class="text-sm font-medium text-memory-violet hover:underline">
                Project home
            </a>
            <a href="{{ route('learn.research.index', $learningProject) }}" class="text-sm font-medium text-memory-violet hover:underline">
                Research
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-[260px_1fr]">
        <aside class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-4">
            <div class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Lessons</div>
            <nav class="space-y-2">
                @foreach ($lessonsNav as $navLesson)
                    @php $active = $navLesson->slug === $lesson->slug; @endphp
                    <a
                        href="{{ route('learn.lessons.show', [$learningProject, $navLesson->slug]) }}"
                        class="block rounded-lg px-3 py-2 text-sm {{ $active ? 'bg-memory-violet/10 text-deep-indigo font-semibold' : 'text-slate-brand hover:bg-memory-violet/5' }}"
                    >
                        {{ $navLesson->title }}
                    </a>
                @endforeach
            </nav>
        </aside>

        <article class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6">
            <div class="prose prose-sm max-w-none text-slate-brand">
                {!! $bodyHtml !!}
            </div>

            @if ($lesson->quiz)
                <section class="mt-10 border-t border-memory-violet/15 pt-8">
                    <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Quiz</h2>

                    @if ($errors->has('quiz'))
                        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                            {{ $errors->first('quiz') }}
                        </div>
                    @endif

                    <div class="mb-6 rounded-xl border border-memory-violet/15 bg-white/70 px-4 py-3 text-sm text-slate-brand">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold text-deep-indigo">{{ $lesson->quiz->title }}</div>
                                <div class="mt-1 text-xs text-slate-brand/70">
                                    Passing score: <span class="font-mono">{{ $lesson->quiz->passing_score }}</span>%
                                </div>
                            </div>

                            @if ($lessonProgress?->completed_at)
                                <div class="text-xs font-semibold text-neural-teal">Lesson marked complete</div>
                            @endif
                        </div>

                        @if ($latestQuizAttempt)
                            <div class="mt-3 text-xs text-slate-brand/80">
                                Latest attempt:
                                <span class="font-mono">{{ $latestQuizAttempt->score }}%</span>
                                @if ($latestQuizAttempt->passed)
                                    <span class="text-neural-teal font-semibold">passed</span>
                                @else
                                    <span class="text-deep-indigo font-semibold">not passed</span>
                                @endif
                                <span class="text-slate-brand/60">({{ $latestQuizAttempt->created_at->diffForHumans() }})</span>
                            </div>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('learn.lessons.quiz.store', [$learningProject, $lesson->slug]) }}" class="space-y-8">
                        @csrf
                        <input type="hidden" name="content_version" value="{{ $lesson->content_version }}" />

                        @foreach ($lesson->quiz->questions as $question)
                            <div class="rounded-xl border border-memory-violet/15 bg-white/70 p-4">
                                <div class="text-sm font-semibold text-deep-indigo">{{ $question->prompt }}</div>

                                <div class="mt-3 space-y-2">
                                    @foreach ($question->options as $idx => $label)
                                        <label class="flex items-start gap-3 text-sm text-slate-brand">
                                            <input
                                                type="radio"
                                                name="answers[{{ $question->id }}]"
                                                value="{{ $idx }}"
                                                class="mt-1"
                                                @checked(old('answers.'.$question->id) !== null && (string) old('answers.'.$question->id) === (string) $idx)
                                            />
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-memory-violet px-4 py-2 text-sm font-semibold text-white hover:bg-memory-violet/90">
                            Submit quiz
                        </button>
                    </form>

                    @if ($recentQuizAttempts->isNotEmpty())
                        <div class="mt-8">
                            <div class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Recent attempts</div>
                            <ul class="space-y-2 text-xs text-slate-brand">
                                @foreach ($recentQuizAttempts as $attempt)
                                    <li class="flex flex-wrap gap-x-3 gap-y-1">
                                        <span class="font-mono">{{ $attempt->score }}%</span>
                                        <span>{{ $attempt->passed ? 'passed' : 'not passed' }}</span>
                                        <span class="text-slate-brand/60">{{ $attempt->created_at->toDateTimeString() }}</span>
                                        @if ($attempt->lesson_content_version !== null)
                                            <span class="text-slate-brand/60">lesson v{{ $attempt->lesson_content_version }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>
            @endif

            <section class="mt-10 border-t border-memory-violet/15 pt-8">
                <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Progress</h2>

                @if ($errors->has('progress'))
                    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ $errors->first('progress') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('learn.lessons.progress.update', [$learningProject, $lesson->slug]) }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="content_version" value="{{ $lesson->content_version }}" />

                    <div>
                        <label class="block text-xs font-semibold text-deep-indigo mb-2" for="learning_bookmark_position">Bookmark</label>
                        <input
                            id="learning_bookmark_position"
                            name="bookmark_position"
                            value="{{ old('bookmark_position', $lessonProgress->bookmark_position ?? '') }}"
                            class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
                            placeholder="Optional anchor text / heading / URL fragment"
                        />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-brand">
                        <input
                            type="checkbox"
                            name="completed"
                            value="1"
                            @checked(old('completed', $lessonProgress?->completed_at !== null))
                        />
                        Mark lesson complete
                    </label>

                    <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-memory-violet/30 bg-white px-4 py-2 text-sm font-semibold text-deep-indigo hover:bg-memory-violet/5">
                        Save progress
                    </button>
                </form>
            </section>

            <section class="mt-10 border-t border-memory-violet/15 pt-8">
                <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Lesson notes</h2>

                <form method="POST" action="{{ route('learn.lessons.notes.store', [$learningProject, $lesson->slug]) }}" class="space-y-4">
                    @csrf

                    <div>
                        <label class="block text-xs font-semibold text-deep-indigo mb-2" for="learning_lesson_note">Note</label>
                        <textarea
                            id="learning_lesson_note"
                            name="body"
                            rows="5"
                            class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
                            required
                        >{{ old('body') }}</textarea>
                    </div>

                    <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-memory-violet/30 bg-white px-4 py-2 text-sm font-semibold text-deep-indigo hover:bg-memory-violet/5">
                        Save note
                    </button>
                </form>
            </section>

            <section class="mt-10 border-t border-memory-violet/15 pt-8">
                <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Related thoughts</h2>

                @if ($relatedThoughts->isEmpty())
                    <div class="text-sm text-slate-brand">
                        No related thoughts yet (or semantic search is unavailable in this environment).
                    </div>
                @else
                    <ul class="space-y-3">
                        @foreach ($relatedThoughts as $thought)
                            <li class="rounded-xl border border-memory-violet/15 bg-white/70 px-4 py-3">
                                <div class="text-xs text-slate-brand/70 mb-1">
                                    <a class="font-medium text-memory-violet hover:underline" href="{{ route('thoughts.show', $thought) }}">Open</a>
                                    <span class="mx-2 text-memory-violet/40">|</span>
                                    <span>{{ $thought->created_at->toDateString() }}</span>
                                </div>
                                <div class="text-sm text-deep-indigo">
                                    {{ \Illuminate\Support\Str::limit($thought->getDecodedContent(), 220) }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="mt-10 border-t border-memory-violet/15 pt-8">
                <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Capture</h2>

                @if ($errors->any())
                    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <div class="font-semibold mb-1">Fix the form</div>
                        <ul class="list-disc pl-5 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('learn.lessons.capture', [$learningProject, $lesson->slug]) }}" class="space-y-4">
                    @csrf

                    <div>
                        <div class="text-xs font-semibold text-deep-indigo mb-2">Artifact type</div>
                        <select name="artifact_type" class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo">
                            <option value="takeaway" @selected(old('artifact_type') === 'takeaway')>Takeaway</option>
                            <option value="confusion" @selected(old('artifact_type') === 'confusion')>Confusion note</option>
                            <option value="lesson_summary" @selected(old('artifact_type') === 'lesson_summary')>Lesson summary</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-deep-indigo mb-2" for="learning_capture_content">Content</label>
                        <textarea
                            id="learning_capture_content"
                            name="content"
                            rows="6"
                            class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
                            required
                        >{{ old('content') }}</textarea>
                    </div>

                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-memory-violet px-4 py-2 text-sm font-semibold text-white hover:bg-memory-violet/90">
                        Save to thoughts
                    </button>
                </form>
            </section>
        </article>
    </div>
</div>
@endsection
