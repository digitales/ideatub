@extends('layouts.idea')

@section('title', 'Completed ideas — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">

    <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Completed ideas</h1>
    <p class="text-center text-sm text-slate-brand/70 mb-6">Ideas you have marked complete.</p>

    @include('idea.partials.ideas_section_nav', ['active' => 'completed'])

    @include('idea.partials.completed_ideas_list', ['ideas' => $ideas])
</div>
@endsection
