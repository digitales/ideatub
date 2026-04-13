@extends('layouts.idea')

@section('title', $project->title.' — Project — IdeaTub')

@section('content')
<div class="max-w-[640px] mx-auto px-6 pt-16 pb-24">
    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">{{ $project->title }}</h1>
            @if ($project->description)
                <div class="mt-3 prose prose-sm max-w-none text-slate-brand">
                    {!! Str::markdown($project->description, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                </div>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('projects.edit', $project) }}" class="text-sm font-medium text-memory-violet hover:underline">Edit</a>
            <form method="POST" action="{{ route('projects.destroy', $project) }}" onsubmit="return confirm('Archive this project? Thoughts stay in your library.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-sm text-red-600 hover:underline">Archive</button>
            </form>
        </div>
    </div>

    <section class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 mb-8">
        <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Add thought</h2>
        <form method="POST" action="{{ route('projects.thoughts.store', $project) }}" class="flex flex-col sm:flex-row gap-3">
            @csrf
            <select name="thought_id" required class="flex-1 rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo">
                <option value="">Choose a thought…</option>
                @foreach ($thoughtOptions as $t)
                    <option value="{{ $t->id }}">{{ \Illuminate\Support\Str::limit($t->content, 80) }}</option>
                @endforeach
            </select>
            <button type="submit" class="rounded-lg bg-memory-violet text-white text-sm font-medium px-4 py-2 hover:opacity-90 whitespace-nowrap">Add</button>
        </form>
        @error('thought_id')
            <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </section>

    <section>
        <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Members</h2>
        @if ($project->thoughts->isEmpty())
            <p class="text-sm text-slate-brand/70">No thoughts in this project yet.</p>
        @else
            <ul class="space-y-2">
                @foreach ($project->thoughts as $thought)
                    <li class="flex items-start justify-between gap-3 rounded-xl border border-memory-violet/10 bg-white/60 px-4 py-3">
                        <a href="{{ route('thoughts.show', $thought) }}" class="text-sm text-deep-indigo hover:text-memory-violet line-clamp-3">
                            {{ \Illuminate\Support\Str::limit($thought->content, 200) }}
                        </a>
                        <form method="POST" action="{{ route('projects.thoughts.destroy', [$project, $thought]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-slate-brand hover:text-red-600 shrink-0">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
