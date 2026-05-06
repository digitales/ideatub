<?php

namespace App\Http\Controllers\Learning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\LearningCaptureRequest;
use App\Models\LearningLesson;
use App\Models\LearningProject;
use App\Services\Learning\LearningThoughtBridge;
use Illuminate\Http\RedirectResponse;

class LearningCaptureController extends Controller
{
    public function store(
        LearningCaptureRequest $request,
        LearningProject $learning_project,
        string $slug,
        LearningThoughtBridge $bridge,
    ): RedirectResponse {
        $this->authorize('view', $learning_project);

        $lesson = LearningLesson::query()
            ->where('learning_project_id', $learning_project->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        $bridge->capture($lesson, (int) $user->id, [
            'artifact_type' => (string) $request->validated('artifact_type'),
            'content' => (string) $request->validated('content'),
        ]);

        return redirect()
            ->route('learn.lessons.show', [$learning_project, $lesson->slug])
            ->with('success', 'Saved to your thoughts.');
    }
}
