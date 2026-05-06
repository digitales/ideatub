<?php

namespace App\Http\Controllers\Learning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\SubmitLearningQuizRequest;
use App\Models\LearningLesson;
use App\Models\LearningProject;
use App\Models\User;
use App\Services\Learning\LearningQuizGradingService;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

class LearningQuizAttemptController extends Controller
{
    public function store(
        LearningProject $learning_project,
        string $slug,
        SubmitLearningQuizRequest $request,
        LearningQuizGradingService $grading,
    ): RedirectResponse {
        $this->authorize('view', $learning_project);

        $lesson = LearningLesson::query()
            ->where('learning_project_id', $learning_project->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $quiz = $lesson->quiz()->with('questions')->first();
        if ($quiz === null) {
            abort(404);
        }

        /** @var User $user */
        $user = $request->user();

        $answers = $request->validated('answers');
        if (! is_array($answers)) {
            $answers = [];
        }

        /** @var array<string, mixed> $answers */
        $normalized = [];
        foreach ($answers as $questionId => $value) {
            $normalized[(string) $questionId] = $value;
        }

        try {
            $attempt = $grading->submit(
                $quiz,
                $user,
                $normalized,
                (int) $request->validated('content_version'),
            );
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('learn.lessons.show', [$learning_project, $lesson->slug])
                ->withErrors(['quiz' => $e->getMessage()])
                ->withInput();
        }

        $message = sprintf(
            'Quiz scored %d%% (%s). Passing score: %d%%.',
            $attempt->score,
            $attempt->passed ? 'passed' : 'not passed',
            $quiz->passing_score,
        );

        return redirect()
            ->route('learn.lessons.show', [$learning_project, $lesson->slug])
            ->with('success', $message);
    }
}
