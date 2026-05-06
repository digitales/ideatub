<?php

namespace App\Http\Controllers\Learning;

use App\Http\Controllers\Controller;
use App\Models\LearningProject;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class LearningProjectController extends Controller
{
    public function index(): View
    {
        $projects = LearningProject::query()
            ->where('user_id', Auth::id())
            ->orderBy('title')
            ->get();

        return view('learning.projects.index', [
            'projects' => $projects,
        ]);
    }

    public function show(LearningProject $learning_project): View
    {
        $this->authorize('view', $learning_project);

        $researchCount = $learning_project->researchDocuments()->count();
        $lessonCount = $learning_project->lessons()->count();

        return view('learning.projects.show', [
            'learningProject' => $learning_project,
            'researchCount' => $researchCount,
            'lessonCount' => $lessonCount,
        ]);
    }
}
