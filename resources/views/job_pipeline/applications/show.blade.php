@extends('layouts.idea')

@section('content')
<h1 class="text-xl font-semibold">{{ $application->role_title }} — {{ $application->company->name }}</h1>
<p class="text-sm text-gray-500">Stage: {{ $application->stage }}</p>

<div class="mt-6" x-data="{ cv: @js($application->cv_markdown ?? ''), coverLetter: @js($application->cover_letter_markdown ?? '') }">
    <form method="POST" action="{{ route('job_pipeline.applications.update', $application) }}">
        @csrf
        @method('PATCH')
        <label class="block font-medium">CV (markdown)</label>
        <div class="grid grid-cols-2 gap-4">
            <textarea name="cv_markdown" x-model="cv" rows="12" class="w-full border rounded p-2 font-mono text-sm"></textarea>
            <div>
                <p class="text-xs font-medium text-gray-500 mb-1">Preview</p>
                <pre x-text="cv" class="whitespace-pre-wrap border rounded p-2 text-sm bg-gray-50" style="min-height: 100%;"></pre>
            </div>
        </div>

        <label class="block font-medium mt-4">Cover letter (markdown)</label>
        <div class="grid grid-cols-2 gap-4">
            <textarea name="cover_letter_markdown" x-model="coverLetter" rows="10" class="w-full border rounded p-2 font-mono text-sm"></textarea>
            <div>
                <p class="text-xs font-medium text-gray-500 mb-1">Preview</p>
                <pre x-text="coverLetter" class="whitespace-pre-wrap border rounded p-2 text-sm bg-gray-50" style="min-height: 100%;"></pre>
            </div>
        </div>

        <button type="submit" class="mt-4 px-4 py-2 bg-gray-900 text-white rounded">Save draft</button>
    </form>

    <div class="mt-4 flex gap-2">
        <form method="POST" action="{{ route('job_pipeline.applications.export', [$application, 'cv']) }}">
            @csrf
            <button type="submit" class="px-4 py-2 border rounded">Export CV PDF</button>
        </form>
        <form method="POST" action="{{ route('job_pipeline.applications.export', [$application, 'cover_letter']) }}">
            @csrf
            <button type="submit" class="px-4 py-2 border rounded">Export Cover Letter PDF</button>
        </form>
        @if ($application->cv_pdf_path)
            <a href="{{ route('job_pipeline.applications.download', [$application, 'cv']) }}" class="px-4 py-2 border rounded">Download CV PDF</a>
        @endif
        @if ($application->cover_letter_pdf_path)
            <a href="{{ route('job_pipeline.applications.download', [$application, 'cover_letter']) }}" class="px-4 py-2 border rounded">Download Cover Letter PDF</a>
        @endif
    </div>
</div>

<h2 class="mt-8 font-semibold">Interactions</h2>
<ul class="mt-2 space-y-1">
    @foreach ($application->interactions as $interaction)
        <li class="text-sm">{{ $interaction->occurred_at->toDateString() }} — {{ $interaction->type }} — {{ $interaction->summary }}</li>
    @endforeach
</ul>
@endsection
