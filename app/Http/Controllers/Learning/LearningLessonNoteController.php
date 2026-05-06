<?php

namespace App\Http\Controllers\Learning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\StoreLearningLessonNoteRequest;
use App\Models\LearningLesson;
use App\Models\LearningLessonNote;
use App\Models\LearningProject;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class LearningLessonNoteController extends Controller
{
    public function store(
        LearningProject $learning_project,
        string $slug,
        StoreLearningLessonNoteRequest $request,
    ): RedirectResponse {
        $this->authorize('view', $learning_project);

        $lesson = LearningLesson::query()
            ->where('learning_project_id', $learning_project->id)
            ->where('slug', $slug)
            ->firstOrFail();

        /** @var User $user */
        $user = $request->user();

        LearningLessonNote::query()->create([
            'user_id' => $user->id,
            'learning_lesson_id' => $lesson->id,
            'body' => $request->validated('body'),
        ]);

        return redirect()
            ->route('learn.lessons.show', [$learning_project, $lesson->slug])
            ->with('success', 'Note saved.');
    }
}
