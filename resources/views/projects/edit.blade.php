@extends('layouts.idea')

@section('title', 'Edit project — IdeaTub')

@section('content')
<div class="max-w-[640px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-8">Edit project</h1>

    <form method="POST" action="{{ route('projects.update', $project) }}" class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] space-y-6">
        @csrf
        @method('PUT')
        @include('projects.partials.form', ['project' => $project, 'parentProjectOptions' => $parentProjectOptions])
        <div class="flex justify-end gap-3">
            <a href="{{ route('projects.show', $project) }}" class="text-sm text-slate-brand hover:text-memory-violet py-2">Cancel</a>
            <button type="submit" class="rounded-lg bg-memory-violet text-white text-sm font-medium px-4 py-2 hover:opacity-90">Save</button>
        </div>
    </form>
</div>
@endsection
