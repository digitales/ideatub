<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateApplicationDocumentsRequest;
use App\Models\Application;
use App\Services\Documents\PdfExportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class JobApplicationController extends Controller
{
    public function index()
    {
        if (! config('features.job_search')) {
            abort(404);
        }

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
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('view', $application);

        $application->load(['company', 'interactions', 'researchThought']);

        return view('job_pipeline.applications.show', ['application' => $application]);
    }

    public function update(UpdateApplicationDocumentsRequest $request, Application $application)
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('update', $application);

        $application->update($request->validated());

        return redirect()->route('job_pipeline.applications.show', $application)->with('success', 'Saved.');
    }

    public function export(Application $application, string $document, PdfExportService $pdfExportService)
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('update', $application);

        $pdfExportService->export($application, $document);

        return redirect()->route('job_pipeline.applications.show', $application)->with('success', 'Exported.');
    }

    public function download(Application $application, string $document)
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('view', $application);

        $path = $document === 'cv' ? $application->cv_pdf_path : $application->cover_letter_pdf_path;

        if ($path === null) {
            abort(404);
        }

        $filename = Str::slug($application->role_title.'-'.$document).'.pdf';

        return Storage::disk('local')->download($path, $filename);
    }
}
