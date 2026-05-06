<?php

namespace App\Services\Learning;

use App\Models\LearningLessonProgress;
use App\Models\LearningQuiz;
use App\Models\LearningQuizAttempt;
use App\Models\LearningQuizQuestion;
use App\Models\LearningQuizResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LearningQuizGradingService
{
    /**
     * @param  array<string, int|string|null>  $answersByQuestionId  question UUID => selected option index (or empty for skipped)
     */
    public function submit(LearningQuiz $quiz, User $user, array $answersByQuestionId, int $clientLessonContentVersion): LearningQuizAttempt
    {
        $quiz->loadMissing('learningLesson');

        $lesson = $quiz->learningLesson;
        if ($lesson === null) {
            throw new InvalidArgumentException('Quiz is not attached to a lesson.');
        }

        if ($lesson->content_version !== $clientLessonContentVersion) {
            throw new InvalidArgumentException('Lesson content changed since this page was loaded. Refresh and try again.');
        }

        $questions = $quiz->questions()->get();
        if ($questions->isEmpty()) {
            throw new InvalidArgumentException('This quiz has no questions yet.');
        }

        return DB::transaction(function () use ($quiz, $user, $questions, $answersByQuestionId, $lesson): LearningQuizAttempt {
            $correct = 0;
            $total = $questions->count();

            /** @var LearningQuizAttempt $attempt */
            $attempt = LearningQuizAttempt::query()->create([
                'user_id' => $user->id,
                'learning_quiz_id' => $quiz->id,
                'score' => 0,
                'passed' => false,
                'lesson_content_version' => $lesson->content_version,
            ]);

            foreach ($questions as $question) {
                /** @var LearningQuizQuestion $question */
                $key = (string) $question->id;
                if (! array_key_exists($key, $answersByQuestionId)) {
                    throw new InvalidArgumentException('Submit an answer for every question.');
                }

                $raw = $answersByQuestionId[$key];
                $selected = $this->normalizeSelectedIndex($raw, $question);

                $isCorrect = $selected !== null && $selected === $question->correct_option_index;
                if ($isCorrect) {
                    $correct++;
                }

                LearningQuizResponse::query()->create([
                    'learning_quiz_attempt_id' => $attempt->id,
                    'learning_quiz_question_id' => $question->id,
                    'selected_option_index' => $selected,
                    'correct' => $isCorrect,
                ]);
            }

            $scorePercent = (int) round(($correct / max(1, $total)) * 100);
            $passed = $scorePercent >= (int) $quiz->passing_score;

            $attempt->score = $scorePercent;
            $attempt->passed = $passed;
            $attempt->save();

            if ($passed) {
                $progress = LearningLessonProgress::query()->firstOrNew([
                    'user_id' => $user->id,
                    'learning_lesson_id' => $lesson->id,
                ]);
                $progress->completed_at ??= now();
                $progress->save();
            }

            return $attempt->fresh(['responses']);
        });
    }

    /**
     * @param  int|string|null  $raw
     */
    private function normalizeSelectedIndex(mixed $raw, LearningQuizQuestion $question): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $selected = is_numeric($raw) ? (int) $raw : null;
        if ($selected === null) {
            throw new InvalidArgumentException('Invalid answer selection.');
        }

        $options = $question->options;
        $optionCount = is_array($options) ? count($options) : 0;
        if ($optionCount < 1 || $selected < 0 || $selected >= $optionCount) {
            throw new InvalidArgumentException('Answer selection is out of range for this question.');
        }

        return $selected;
    }
}
