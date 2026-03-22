@extends('layouts.idea')

@section('title', 'Ideas to revisit — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">

    <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Ideas to revisit</h1>
    <p class="text-center text-sm text-slate-brand/70 mb-6">Incomplete ideas, oldest first. <a href="{{ route('settings.ideas-revisit.index') }}" class="text-memory-violet hover:underline">Settings</a></p>

    @include('idea.partials.ideas_section_nav', ['active' => 'revisit'])

    @if (empty($ideas))
        <div class="rounded-xl border border-memory-violet/10 bg-white/50 px-4 py-12 text-center text-sm text-slate-brand/50">
            No ideas to revisit. Add ideas on <a href="{{ route('idea.ideas') }}" class="text-memory-violet hover:underline">Ideas</a> and leave them incomplete to see them here.
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($ideas as $thought)
                <li class="rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3 hover:border-memory-violet/25 transition-colors">
                    <a href="{{ route('idea.index', ['parent_id' => $thought->id]) }}" class="block min-w-0">
                        <p class="text-sm text-deep-indigo line-clamp-2">
                            {{ Str::limit($thought->content, 200) }}
                        </p>
                        <p class="text-[11px] text-slate-brand/50 mt-1">{{ $thought->getLoggedDate() }}</p>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
