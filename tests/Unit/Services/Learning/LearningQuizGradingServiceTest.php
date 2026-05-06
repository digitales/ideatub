<?php

namespace Tests\Unit\Services\Learning;

use App\Models\LearningLesson;
use App\Models\LearningLessonProgress;
use App\Models\LearningProject;
use App\Models\LearningQuiz;
use App\Models\LearningQuizQuestion;
use App\Models\User;
use App\Services\Learning\LearningQuizGradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LearningQuizGradingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLessonWithQuiz(User $owner, int $passingScore = 70): array
    {
        $project = LearningProject::query()->create([
            'user_id' => $owner->id,
            'slug' => 'grading-proj',
            'title' => 'Grading Proj',
            'content_root' => '/tmp/unused',
            'source_url' => null,
        ]);

        $lesson = LearningLesson::query()->create([
            'learning_project_id' => $project->id,
            'slug' => 'lesson-1',
            'title' => 'Lesson 1',
            'stage' => null,
            'difficulty' => null,
            'order' => 1,
            'summary' => null,
            'goals' => null,
            'related_research_slugs' => null,
            'body_markdown' => "# L\n",
            'content_version' => 2,
            'synced_at' => now(),
        ]);

        $quiz = LearningQuiz::query()->create([
            'learning_lesson_id' => $lesson->id,
            'title' => 'Quiz',
            'passing_score' => $passingScore,
        ]);

        $q1 = LearningQuizQuestion::query()->create([
            'learning_quiz_id' => $quiz->id,
            'sort_order' => 0,
            'prompt' => 'Q1',
            'options' => ['A', 'B'],
            'correct_option_index' => 0,
            'explanation' => null,
        ]);

        $q2 = LearningQuizQuestion::query()->create([
            'learning_quiz_id' => $quiz->id,
            'sort_order' => 1,
            'prompt' => 'Q2',
            'options' => ['A', 'B'],
            'correct_option_index' => 1,
            'explanation' => null,
        ]);

        return [$lesson, $quiz, $q1, $q2];
    }

    public function test_all_correct_scores_100_and_passes_and_marks_progress(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        [$lesson, $quiz, $q1, $q2] = $this->makeLessonWithQuiz($user, 70);

        /** @var LearningQuizGradingService $grading */
        $grading = app(LearningQuizGradingService::class);

        $attempt = $grading->submit($quiz, $user, [
            (string) $q1->id => 0,
            (string) $q2->id => 1,
        ], (int) $lesson->content_version);

        $this->assertSame(100, $attempt->score);
        $this->assertTrue($attempt->passed);
        $this->assertSame((int) $lesson->content_version, (int) $attempt->lesson_content_version);

        $this->assertSame(2, $attempt->responses()->count());

        $progress = LearningLessonProgress::query()->where('user_id', $user->id)->where('learning_lesson_id', $lesson->id)->first();
        $this->assertNotNull($progress?->completed_at);
    }

    public function test_partial_score_can_fail_and_skipped_question_counts_wrong(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        [$lesson, $quiz, $q1, $q2] = $this->makeLessonWithQuiz($user, 70);

        /** @var LearningQuizGradingService $grading */
        $grading = app(LearningQuizGradingService::class);

        $attempt = $grading->submit($quiz, $user, [
            (string) $q1->id => 0,
            (string) $q2->id => '', // skipped => incorrect
        ], (int) $lesson->content_version);

        $this->assertSame(50, $attempt->score);
        $this->assertFalse($attempt->passed);

        $wrong = $attempt->responses()->where('correct', false)->count();
        $this->assertSame(1, $wrong);
    }

    public function test_wrong_content_version_is_rejected(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        [$lesson, $quiz, $q1, $q2] = $this->makeLessonWithQuiz($user, 70);

        /** @var LearningQuizGradingService $grading */
        $grading = app(LearningQuizGradingService::class);

        $this->expectException(InvalidArgumentException::class);

        $grading->submit($quiz, $user, [
            (string) $q1->id => 0,
            (string) $q2->id => 1,
        ], (int) $lesson->content_version - 1);
    }

    public function test_unknown_question_key_is_rejected(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        [$lesson, $quiz, $q1, $q2] = $this->makeLessonWithQuiz($user, 70);

        /** @var LearningQuizGradingService $grading */
        $grading = app(LearningQuizGradingService::class);

        $this->expectException(InvalidArgumentException::class);

        $grading->submit($quiz, $user, [
            (string) $q1->id => 0,
            'not-a-real-id' => 0,
        ], (int) $lesson->content_version);
    }
}
