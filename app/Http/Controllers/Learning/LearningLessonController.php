<?php

namespace App\Http\Controllers\Learning;

use App\Http\Controllers\Controller;
use App\Models\LearningLesson;
use App\Models\LearningLessonProgress;
use App\Models\LearningProject;
use App\Models\LearningQuizAttempt;
use App\Models\Thought;
use App\Models\User;
use App\Services\ThoughtSearchService;
use App\Support\SafeCommonMarkConverter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LearningLessonController extends Controller
{
    public function show(LearningProject $learning_project, string $slug, ThoughtSearchService $thoughtSearch): View
    {
        $this->authorize('view', $learning_project);

        $lesson = LearningLesson::query()
            ->where('learning_project_id', $learning_project->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $lesson->load(['quiz.questions']);

        $lessonsNav = $learning_project->lessons()
            ->orderBy('order')
            ->orderBy('title')
            ->get(['id', 'slug', 'title', 'order']);

        $converter = SafeCommonMarkConverter::make();
        $bodyHtml = $converter->convert((string) $lesson->body_markdown)->getContent();

        $relatedThoughts = collect();
        $user = Auth::user();

        $lessonProgress = null;
        $latestQuizAttempt = null;
        $recentQuizAttempts = collect();

        if ($user instanceof User) {
            $lessonProgress = LearningLessonProgress::query()
                ->where('user_id', $user->id)
                ->where('learning_lesson_id', $lesson->id)
                ->first();

            if ($lesson->quiz !== null) {
                $attemptQuery = LearningQuizAttempt::query()
                    ->where('user_id', $user->id)
                    ->where('learning_quiz_id', $lesson->quiz->id);

                $latestQuizAttempt = (clone $attemptQuery)
                    ->latest()
                    ->with('responses')
                    ->first();

                $recentQuizAttempts = (clone $attemptQuery)
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'score', 'passed', 'created_at', 'lesson_content_version']);
            }
        }

        if ($user instanceof User && ! empty(config('services.openrouter.api_key'))) {
            $seed = trim($lesson->title."\n".(string) ($lesson->summary ?? ''));

            try {
                $result = $thoughtSearch->search($seed, (int) $user->id, [
                    'max_distance' => 0.5,
                    'tag_limit' => 100,
                    'semantic_limit' => 100,
                ]);

                /** @var Collection<int, Thought> $relatedThoughts */
                $relatedThoughts = $result['thoughts']->take(5)->values();
            } catch (\Throwable $e) {
                Log::warning('Learning lesson related-thought search failed.', [
                    'lesson_id' => $lesson->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return view('learning.lessons.show', [
            'learningProject' => $learning_project,
            'lesson' => $lesson,
            'lessonsNav' => $lessonsNav,
            'bodyHtml' => $bodyHtml,
            'relatedThoughts' => $relatedThoughts,
            'lessonProgress' => $lessonProgress,
            'latestQuizAttempt' => $latestQuizAttempt,
            'recentQuizAttempts' => $recentQuizAttempts,
        ]);
    }
}
