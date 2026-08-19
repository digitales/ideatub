<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateApplicationDocumentsRequest;
use App\Models\Application;
use App\Services\Documents\PdfExportService;

class JobApplicationController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Application::class);

        $applications = Application::query()
            ->where('user_id', auth()->id())
            ->with('company:id,name')
            ->orderByDesc('last_activity_at')
            ->get()
            ->groupBy('stage');

        return view('job_pipeline.applications.board', ['applicationsByStage' => $applications]);
    }

    public function show(Application $application)
    {
        $this->authorize('view', $application);

        $application->load(['company', 'interactions', 'researchThought']);

        return view('job_pipeline.applications.show', ['application' => $application]);
    }

    public function update(UpdateApplicationDocumentsRequest $request, Application $application)
    {
        $this->authorize('update', $application);

        $application->update($request->validated());

        return redirect()->route('job_pipeline.applications.show', $application)->with('success', 'Saved.');
    }

    public function export(Application $application, string $document, PdfExportService $pdfExportService)
    {
        $this->authorize('update', $application);

        $pdfExportService->export($application, $document);

        return redirect()->route('job_pipeline.applications.show', $application)->with('success', 'Exported.');
    }
}
