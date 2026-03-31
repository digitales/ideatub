@extends('layouts.idea')

@section('title', $skill->name.' — Research skill — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Edit research skill</h1>
    <p class="text-sm text-slate-brand mb-8">{{ $skill->name }}</p>

    @if ($errors->any())
        <div class="mb-6 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            <p class="font-medium">Please fix the errors below.</p>
        </div>
    @endif

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <form method="POST" action="{{ route('settings.research-skills.update', $skill) }}">
            @csrf
            @method('PUT')
            @include('settings.research-skills._form', ['skill' => $skill, 'latest' => $latest])

            <div class="mt-6 flex items-center gap-3">
                <button
                    type="submit"
                    class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                    style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                >
                    Save changes
                </button>
                <a href="{{ route('settings.research-skills.index') }}" class="text-xs font-medium text-slate-brand hover:text-memory-violet">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
