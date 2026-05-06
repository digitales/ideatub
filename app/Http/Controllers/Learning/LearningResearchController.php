<?php

namespace App\Http\Controllers\Learning;

use App\Http\Controllers\Controller;
use App\Models\LearningProject;
use App\Models\LearningResearchDocument;
use App\Support\SafeCommonMarkConverter;
use Illuminate\Contracts\View\View;

class LearningResearchController extends Controller
{
    public function index(LearningProject $learning_project): View
    {
        $this->authorize('view', $learning_project);

        $documents = $learning_project->researchDocuments()
            ->orderBy('title')
            ->get();

        return view('learning.research.index', [
            'learningProject' => $learning_project,
            'documents' => $documents,
        ]);
    }

    public function show(LearningProject $learning_project, string $slug): View
    {
        $this->authorize('view', $learning_project);

        $document = LearningResearchDocument::query()
            ->where('learning_project_id', $learning_project->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $converter = SafeCommonMarkConverter::make();
        $bodyHtml = $converter->convert((string) $document->body_markdown)->getContent();

        return view('learning.research.show', [
            'learningProject' => $learning_project,
            'document' => $document,
            'bodyHtml' => $bodyHtml,
        ]);
    }
}
