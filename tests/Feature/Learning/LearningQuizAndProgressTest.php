<?php

namespace Tests\Feature\Learning;

use App\Models\LearningLesson;
use App\Models\LearningLessonProgress;
use App\Models\LearningProject;
use App\Models\LearningQuiz;
use App\Models\LearningQuizAttempt;
use App\Models\LearningQuizQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningQuizAndProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array{0: LearningLesson, 1: LearningQuiz, 2: LearningQuizQuestion, 3: LearningQuizQuestion}
     */
    private function seedLessonQuiz(User $owner): array
    {
        $project = LearningProject::query()->create([
            'user_id' => $owner->id,
            'slug' => 'quiz-proj',
            'title' => 'Quiz Proj',
            'content_root' => '/tmp/unused',
            'source_url' => null,
        ]);

        $lesson = LearningLesson::query()->create([
            'learning_project_id' => $project->id,
            'slug' => 'lesson-q',
            'title' => 'Lesson Q',
            'stage' => null,
            'difficulty' => null,
            'order' => 1,
            'summary' => null,
            'goals' => null,
            'related_research_slugs' => null,
            'body_markdown' => "# Q\n",
            'content_version' => 3,
            'synced_at' => now(),
        ]);

        $quiz = LearningQuiz::query()->create([
            'learning_lesson_id' => $lesson->id,
            'title' => 'Lesson quiz',
            'passing_score' => 70,
        ]);

        $q1 = LearningQuizQuestion::query()->create([
            'learning_quiz_id' => $quiz->id,
            'sort_order' => 0,
            'prompt' => 'Pick A',
            'options' => ['A', 'B'],
            'correct_option_index' => 0,
            'explanation' => null,
        ]);

        $q2 = LearningQuizQuestion::query()->create([
            'learning_quiz_id' => $quiz->id,
            'sort_order' => 1,
            'prompt' => 'Pick B',
            'options' => ['A', 'B'],
            'correct_option_index' => 1,
            'explanation' => null,
        ]);

        return [$lesson, $quiz, $q1, $q2];
    }

    public function test_owner_can_submit_quiz_and_records_attempt_and_progress_when_passed(): void
    {
        /** @var User $owner */
        $owner = User::factory()->createOne();

        [$lesson, $quiz, $q1, $q2] = $this->seedLessonQuiz($owner);

        $response = $this->actingAs($owner)->post(route('learn.lessons.quiz.store', [$lesson->learningProject, $lesson->slug]), [
            '_token' => csrf_token(),
            'content_version' => $lesson->content_version,
            'answers' => [
                $q1->id => 0,
                $q2->id => 1,
            ],
        ]);

        $response->assertRedirect(route('learn.lessons.show', [$lesson->learningProject, $lesson->slug]));
        $response->assertSessionHas('success');

        $attempt = LearningQuizAttempt::query()->where('user_id', $owner->id)->where('learning_quiz_id', $quiz->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(100, $attempt->score);
        $this->assertTrue($attempt->passed);

        $progress = LearningLessonProgress::query()->where('user_id', $owner->id)->where('learning_lesson_id', $lesson->id)->first();
        $this->assertNotNull($progress?->completed_at);
    }

    public function test_stale_content_version_quiz_submission_is_rejected(): void
    {
        /** @var User $owner */
        $owner = User::factory()->createOne();

        [$lesson, , $q1, $q2] = $this->seedLessonQuiz($owner);

        $response = $this->actingAs($owner)->post(route('learn.lessons.quiz.store', [$lesson->learningProject, $lesson->slug]), [
            '_token' => csrf_token(),
            'content_version' => $lesson->content_version - 1,
            'answers' => [
                $q1->id => 0,
                $q2->id => 1,
            ],
        ]);

        $response->assertRedirect(route('learn.lessons.show', [$lesson->learningProject, $lesson->slug]));
        $response->assertSessionHasErrors('quiz');

        $this->assertSame(0, LearningQuizAttempt::query()->where('user_id', $owner->id)->count());
    }

    public function test_owner_can_save_progress_and_non_owner_is_forbidden(): void
    {
        /** @var User $owner */
        $owner = User::factory()->createOne();
        /** @var User $other */
        $other = User::factory()->createOne();

        $project = LearningProject::query()->create([
            'user_id' => $owner->id,
            'slug' => 'prog-proj',
            'title' => 'Prog Proj',
            'content_root' => '/tmp/unused',
            'source_url' => null,
        ]);

        $lesson = LearningLesson::query()->create([
            'learning_project_id' => $project->id,
            'slug' => 'lesson-p',
            'title' => 'Lesson P',
            'stage' => null,
            'difficulty' => null,
            'order' => 1,
            'summary' => null,
            'goals' => null,
            'related_research_slugs' => null,
            'body_markdown' => "# P\n",
            'content_version' => 4,
            'synced_at' => now(),
        ]);

        $ok = $this->actingAs($owner)->post(route('learn.lessons.progress.update', [$project, $lesson->slug]), [
            '_token' => csrf_token(),
            'content_version' => $lesson->content_version,
            'bookmark_position' => 'Section: Diagnostics',
            'completed' => true,
        ]);

        $ok->assertRedirect(route('learn.lessons.show', [$project, $lesson->slug]));

        $progress = LearningLessonProgress::query()->where('user_id', $owner->id)->where('learning_lesson_id', $lesson->id)->first();
        $this->assertSame('Section: Diagnostics', $progress?->bookmark_position);
        $this->assertNotNull($progress?->completed_at);

        $bad = $this->actingAs($other)->post(route('learn.lessons.progress.update', [$project, $lesson->slug]), [
            '_token' => csrf_token(),
            'content_version' => $lesson->content_version,
            'bookmark_position' => 'x',
        ]);

        $bad->assertForbidden();
    }

    public function test_stale_content_version_progress_update_is_rejected(): void
    {
        /** @var User $owner */
        $owner = User::factory()->createOne();

        $project = LearningProject::query()->create([
            'user_id' => $owner->id,
            'slug' => 'prog-proj-2',
            'title' => 'Prog Proj 2',
            'content_root' => '/tmp/unused',
            'source_url' => null,
        ]);

        $lesson = LearningLesson::query()->create([
            'learning_project_id' => $project->id,
            'slug' => 'lesson-p2',
            'title' => 'Lesson P2',
            'stage' => null,
            'difficulty' => null,
            'order' => 1,
            'summary' => null,
            'goals' => null,
            'related_research_slugs' => null,
            'body_markdown' => "# P2\n",
            'content_version' => 9,
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($owner)->post(route('learn.lessons.progress.update', [$project, $lesson->slug]), [
            '_token' => csrf_token(),
            'content_version' => 8,
            'bookmark_position' => 'x',
        ]);

        $response->assertRedirect(route('learn.lessons.show', [$project, $lesson->slug]));
        $response->assertSessionHasErrors('progress');

        $this->assertSame(0, LearningLessonProgress::query()->where('user_id', $owner->id)->where('learning_lesson_id', $lesson->id)->count());
    }
}
