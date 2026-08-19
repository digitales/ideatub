@extends('layouts.idea')

@section('content')
<div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    @foreach (\App\Models\Application::STAGES as $stage)
        <div>
            <h2 class="font-semibold text-sm uppercase text-gray-500 mb-2">{{ str($stage)->headline() }}</h2>
            <div class="space-y-2">
                @foreach ($applicationsByStage->get($stage, collect()) as $application)
                    <a href="{{ route('job_pipeline.applications.show', $application) }}"
                       class="block border rounded p-3 hover:bg-gray-50">
                        <div class="font-medium">{{ $application->role_title }}</div>
                        <div class="text-sm text-gray-500">{{ $application->company->name }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@endsection
