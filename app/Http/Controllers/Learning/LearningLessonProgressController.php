<?php

namespace App\Http\Controllers\Learning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\UpsertLearningProgressRequest;
use App\Models\LearningLesson;
use App\Models\LearningLessonProgress;
use App\Models\LearningProject;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class LearningLessonProgressController extends Controller
{
    public function update(
        LearningProject $learning_project,
        string $slug,
        UpsertLearningProgressRequest $request,
    ): RedirectResponse {
        $this->authorize('view', $learning_project);

        $lesson = LearningLesson::query()
            ->where('learning_project_id', $learning_project->id)
            ->where('slug', $slug)
            ->firstOrFail();

        /** @var User $user */
        $user = $request->user();

        $expectedVersion = (int) $request->validated('content_version');
        if ($lesson->content_version !== $expectedVersion) {
            return redirect()
                ->route('learn.lessons.show', [$learning_project, $lesson->slug])
                ->withErrors(['progress' => 'Lesson content changed since this page was loaded. Refresh and try again.']);
        }

        /** @var LearningLessonProgress $progress */
        $progress = LearningLessonProgress::query()->firstOrNew([
            'user_id' => $user->id,
            'learning_lesson_id' => $lesson->id,
        ]);

        $bookmark = $request->validated('bookmark_position');
        if ($bookmark !== null && $bookmark !== '') {
            $progress->bookmark_position = $bookmark;
        }

        if ($request->boolean('completed')) {
            $progress->completed_at ??= now();
        }

        $progress->save();

        return redirect()
            ->route('learn.lessons.show', [$learning_project, $lesson->slug])
            ->with('success', 'Progress saved.');
    }
}
