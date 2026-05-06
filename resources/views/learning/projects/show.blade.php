@extends('layouts.idea')

@section('title', $learningProject->title.' — Learn — IdeaTub')

@section('content')
<div class="max-w-[920px] mx-auto px-6 pt-16 pb-24">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-8">
        <div>
            <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">{{ $learningProject->title }}</h1>
            <div class="mt-2 text-xs text-slate-brand/80">
                slug: <span class="font-mono">{{ $learningProject->slug }}</span>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('learn.research.index', $learningProject) }}" class="text-sm font-medium text-memory-violet hover:underline">
                Research ({{ $researchCount }})
            </a>
        </div>
    </div>

    <section class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6">
        <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Lessons</h2>

        @php
            $lessons = $learningProject->lessons()->orderBy('order')->orderBy('title')->get();
        @endphp

        @if ($lessons->isEmpty())
            <div class="text-sm text-slate-brand">No lessons synced yet. Run <span class="font-mono">php artisan learning:sync</span> after content is on disk.</div>
        @else
            <ul class="space-y-2">
                @foreach ($lessons as $lesson)
                    <li>
                        <a class="text-sm font-medium text-deep-indigo hover:underline" href="{{ route('learn.lessons.show', [$learningProject, $lesson->slug]) }}">
                            {{ $lesson->title }}
                        </a>
                        @if ($lesson->stage)
                            <span class="ml-2 text-xs text-slate-brand/70">{{ $lesson->stage }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
