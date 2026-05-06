<?php

namespace App\Services\Learning;

use App\Models\LearningLesson;
use App\Models\LearningProject;
use App\Models\LearningQuiz;
use App\Models\LearningQuizQuestion;
use App\Models\LearningResearchDocument;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LearningSyncService
{
    public function __construct(
        private readonly LearningMarkdownFrontmatterParser $parser,
        private readonly LearningContentPaths $paths,
    ) {}

    /**
     * Sync markdown content from disk into the database for the given project.
     *
     * @return array{research: int, lessons: int}
     */
    public function sync(LearningProject $project): array
    {
        $contentRoot = $project->content_root;

        if ($contentRoot === '' || $contentRoot === null) {
            throw new InvalidArgumentException('Learning project content_root is empty.');
        }

        if (! is_dir($contentRoot) || ! is_readable($contentRoot)) {
            throw new InvalidArgumentException(sprintf('Content root is not a readable directory: %s', $contentRoot));
        }

        $researchPaths = $this->paths->researchGlob($contentRoot);
        $lessonPaths = $this->paths->lessonGlob($contentRoot);

        $researchParsed = [];
        foreach ($researchPaths as $path) {
            $researchParsed[] = $this->parseResearchFile($path);
        }

        $lessonsParsed = [];
        foreach ($lessonPaths as $path) {
            $lessonsParsed[] = $this->parseLessonFile($path);
        }

        $researchSlugs = array_map(fn (array $r) => $r['slug'], $researchParsed);
        $lessonSlugs = array_map(fn (array $l) => $l['slug'], $lessonsParsed);

        return DB::transaction(function () use ($project, $researchParsed, $lessonsParsed, $researchSlugs, $lessonSlugs): array {
            foreach ($researchParsed as $row) {
                $this->upsertResearch($project, $row);
            }

            foreach ($lessonsParsed as $row) {
                $this->upsertLesson($project, $row);
            }

            LearningResearchDocument::query()
                ->where('learning_project_id', $project->id)
                ->whereNotIn('slug', $researchSlugs)
                ->delete();

            LearningLesson::query()
                ->where('learning_project_id', $project->id)
                ->whereNotIn('slug', $lessonSlugs)
                ->delete();

            return [
                'research' => count($researchParsed),
                'lessons' => count($lessonsParsed),
            ];
        });
    }

    /**
     * @return array{slug: string, title: string, body: string, summary: ?string, category: ?string, source_url: ?string}
     */
    private function parseResearchFile(string $path): array
    {
        $markdown = $this->readFile($path);
        $parsed = $this->parser->parse($markdown);
        /** @var array<string, mixed> $fm */
        $fm = $parsed['frontmatter'];

        return [
            'slug' => $fm['slug'],
            'title' => $fm['title'],
            'body' => $parsed['body'],
            'summary' => $this->optionalString($fm, 'summary'),
            'category' => $this->optionalString($fm, 'category'),
            'source_url' => $this->optionalString($fm, 'source_url'),
        ];
    }

    /**
     * @return array{
     *     slug: string,
     *     title: string,
     *     body: string,
     *     stage: ?string,
     *     difficulty: ?string,
     *     order: int,
     *     summary: ?string,
     *     goals: ?array,
     *     related_research_slugs: ?array,
     *     quiz: ?array{
     *         title: string,
     *         passing_score: int,
     *         questions: list<array{
     *             prompt: string,
     *             options: list<string>,
     *             correct_option_index: int,
     *             explanation: ?string
     *         }>
     *     }
     * }
     */
    private function parseLessonFile(string $path): array
    {
        $markdown = $this->readFile($path);
        $parsed = $this->parser->parse($markdown);
        /** @var array<string, mixed> $fm */
        $fm = $parsed['frontmatter'];

        $quizRaw = $fm['quiz'] ?? null;
        $normalizedQuiz = $this->normalizeQuizBlock($quizRaw);

        return [
            'slug' => $fm['slug'],
            'title' => $fm['title'],
            'body' => $parsed['body'],
            'stage' => $this->optionalString($fm, 'stage'),
            'difficulty' => $this->optionalString($fm, 'difficulty'),
            'order' => $this->optionalUnsignedInt($fm, 'order', 0),
            'summary' => $this->optionalString($fm, 'summary'),
            'goals' => $this->optionalStringListFromKeys($fm, ['goals']),
            'related_research_slugs' => $this->optionalStringListFromKeys($fm, ['related_research_slugs', 'relatedResearch']),
            'quiz' => $normalizedQuiz,
        ];
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    private function upsertResearch(LearningProject $project, array $row): void
    {
        LearningResearchDocument::query()->updateOrCreate(
            [
                'learning_project_id' => $project->id,
                'slug' => $row['slug'],
            ],
            [
                'title' => $row['title'],
                'body_markdown' => $row['body'],
                'summary' => $row['summary'],
                'category' => $row['category'],
                'source_url' => $row['source_url'],
                'synced_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertLesson(LearningProject $project, array $row): void
    {
        $existing = LearningLesson::query()
            ->where('learning_project_id', $project->id)
            ->where('slug', $row['slug'])
            ->first();

        $newBody = $row['body'];
        $newQuizHash = $this->hashNormalizedQuizFromParsed($row['quiz']);

        if ($existing === null) {
            $contentVersion = 1;
        } else {
            $existing->load(['quiz.questions']);
            $oldQuizHash = $this->hashNormalizedQuizFromModels($existing);
            $contentVersion = (int) $existing->content_version;
            if ($existing->body_markdown !== $newBody || $oldQuizHash !== $newQuizHash) {
                $contentVersion++;
            }
        }

        $lesson = LearningLesson::query()->updateOrCreate(
            [
                'learning_project_id' => $project->id,
                'slug' => $row['slug'],
            ],
            [
                'title' => $row['title'],
                'stage' => $row['stage'],
                'difficulty' => $row['difficulty'],
                'order' => $row['order'],
                'summary' => $row['summary'],
                'goals' => $row['goals'],
                'related_research_slugs' => $row['related_research_slugs'],
                'body_markdown' => $newBody,
                'content_version' => $contentVersion,
                'synced_at' => now(),
            ]
        );

        $this->replaceLessonQuiz($lesson, $row['quiz']);
    }

    /**
     * @param  ?array{
     *     title: string,
     *     passing_score: int,
     *     questions: list<array{
     *         prompt: string,
     *         options: list<string>,
     *         correct_option_index: int,
     *         explanation: ?string
     *     }>
     * }  $quiz
     */
    private function replaceLessonQuiz(LearningLesson $lesson, ?array $quiz): void
    {
        LearningQuiz::query()->where('learning_lesson_id', $lesson->id)->delete();

        if ($quiz === null) {
            return;
        }

        $created = LearningQuiz::query()->create([
            'learning_lesson_id' => $lesson->id,
            'title' => $quiz['title'],
            'passing_score' => $quiz['passing_score'],
        ]);

        foreach ($quiz['questions'] as $index => $question) {
            LearningQuizQuestion::query()->create([
                'learning_quiz_id' => $created->id,
                'sort_order' => $index,
                'prompt' => $question['prompt'],
                'options' => $question['options'],
                'correct_option_index' => $question['correct_option_index'],
                'explanation' => $question['explanation'],
            ]);
        }
    }

    private function hashNormalizedQuizFromParsed(?array $quiz): string
    {
        if ($quiz === null) {
            return '';
        }

        return $this->hashNormalizedQuizPayload([
            'title' => $quiz['title'],
            'passing_score' => $quiz['passing_score'],
            'questions' => array_map(fn (array $q): array => [
                'prompt' => $q['prompt'],
                'options' => $q['options'],
                'correct_option_index' => $q['correct_option_index'],
                'explanation' => $q['explanation'],
            ], $quiz['questions']),
        ]);
    }

    private function hashNormalizedQuizFromModels(LearningLesson $lesson): string
    {
        $quiz = $lesson->quiz;
        if ($quiz === null) {
            return '';
        }

        $questions = $quiz->questions()->orderBy('sort_order')->get();

        return $this->hashNormalizedQuizPayload([
            'title' => $quiz->title,
            'passing_score' => (int) $quiz->passing_score,
            'questions' => $questions->map(fn (LearningQuizQuestion $q): array => [
                'prompt' => $q->prompt,
                'options' => array_values(array_map('strval', $q->options ?? [])),
                'correct_option_index' => (int) $q->correct_option_index,
                'explanation' => $q->explanation,
            ])->all(),
        ]);
    }

    /**
     * @param  array{
     *     title: string,
     *     passing_score: int,
     *     questions: list<array{
     *         prompt: string,
     *         options: list<string>,
     *         correct_option_index: int,
     *         explanation: ?string
     *     }>
     * }  $payload
     */
    private function hashNormalizedQuizPayload(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeQuizBlock(mixed $quizRaw): ?array
    {
        if ($quizRaw === null) {
            return null;
        }

        if (! is_array($quizRaw)) {
            throw new InvalidArgumentException('Lesson frontmatter key "quiz" must be a mapping when present.');
        }

        if ($quizRaw === []) {
            return null;
        }

        $title = $quizRaw['title'] ?? null;
        if (! is_string($title) || trim($title) === '') {
            throw new InvalidArgumentException('Quiz title must be a non-empty string when quiz is present.');
        }

        $passing = $quizRaw['passingScore'] ?? $quizRaw['passing_score'] ?? 70;
        if (! is_int($passing) && ! is_float($passing) && ! is_string($passing)) {
            throw new InvalidArgumentException('Quiz passing score must be numeric.');
        }
        $passingScore = (int) $passing;
        if ($passingScore < 0 || $passingScore > 100) {
            throw new InvalidArgumentException('Quiz passing score must be between 0 and 100.');
        }

        $questionsRaw = $quizRaw['questions'] ?? null;
        if (! is_array($questionsRaw) || $questionsRaw === []) {
            throw new InvalidArgumentException('Quiz must include a non-empty questions array.');
        }

        $questions = [];
        foreach ($questionsRaw as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Each quiz question must be a mapping.');
            }

            $prompt = $item['prompt'] ?? null;
            if (! is_string($prompt) || trim($prompt) === '') {
                throw new InvalidArgumentException('Quiz question prompt must be a non-empty string.');
            }

            $options = $this->normalizeQuizOptions($item['options'] ?? null);
            $correctRaw = $item['correctOption'] ?? $item['correct_option'] ?? null;
            $correctIndex = $this->resolveCorrectOptionIndex($correctRaw, $options);

            $explanation = $item['explanation'] ?? null;
            if ($explanation !== null && ! is_string($explanation)) {
                throw new InvalidArgumentException('Quiz question explanation must be a string or omitted.');
            }

            $questions[] = [
                'prompt' => $prompt,
                'options' => $options,
                'correct_option_index' => $correctIndex,
                'explanation' => $explanation,
            ];
        }

        return [
            'title' => $title,
            'passing_score' => $passingScore,
            'questions' => $questions,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeQuizOptions(mixed $options): array
    {
        if (! is_array($options)) {
            throw new InvalidArgumentException('Quiz question options must be an array.');
        }

        $out = [];
        foreach ($options as $opt) {
            if (is_string($opt)) {
                $out[] = $opt;
            } elseif (is_int($opt) || is_float($opt)) {
                $out[] = (string) $opt;
            } else {
                throw new InvalidArgumentException('Each quiz option must be a string or number.');
            }
        }

        if ($out === []) {
            throw new InvalidArgumentException('Quiz question must include at least one option.');
        }

        return array_values($out);
    }

    /**
     * @param  list<string>  $options
     */
    private function resolveCorrectOptionIndex(mixed $correctOption, array $options): int
    {
        $count = count($options);

        if (is_int($correctOption)) {
            if ($correctOption < 0 || $correctOption >= $count) {
                throw new InvalidArgumentException('Quiz correctOption index is out of range.');
            }

            return $correctOption;
        }

        if (is_float($correctOption)) {
            $idx = (int) $correctOption;
            if ($idx !== $correctOption) {
                throw new InvalidArgumentException('Quiz correctOption float index must be a whole number.');
            }
            if ($idx < 0 || $idx >= $count) {
                throw new InvalidArgumentException('Quiz correctOption index is out of range.');
            }

            return $idx;
        }

        if (is_string($correctOption)) {
            $trimmed = trim($correctOption);
            if ($trimmed === '') {
                throw new InvalidArgumentException('Quiz correctOption cannot be empty.');
            }

            foreach ($options as $i => $opt) {
                if ($opt === $correctOption || $opt === $trimmed) {
                    return $i;
                }
            }

            if (ctype_digit($trimmed)) {
                $idx = (int) $trimmed;
                if ($idx >= 0 && $idx < $count) {
                    return $idx;
                }

                throw new InvalidArgumentException('Quiz correctOption numeric index is out of range.');
            }

            throw new InvalidArgumentException('Quiz correctOption does not match any option text.');
        }

        throw new InvalidArgumentException('Quiz correctOption must be an index or option text.');
    }

    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Unable to read file: %s', $path));
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $fm
     */
    private function optionalString(array $fm, string $key): ?string
    {
        if (! array_key_exists($key, $fm)) {
            return null;
        }

        $value = $fm[$key];
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Frontmatter key "%s" must be a string when set.', $key));
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $fm
     */
    private function optionalUnsignedInt(array $fm, string $key, int $default): int
    {
        if (! array_key_exists($key, $fm)) {
            return $default;
        }

        $value = $fm[$key];
        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && ctype_digit($value)) {
            return max(0, (int) $value);
        }

        if (is_float($value)) {
            return max(0, (int) $value);
        }

        throw new InvalidArgumentException(sprintf('Frontmatter key "%s" must be a non-negative integer when set.', $key));
    }

    /**
     * @param  array<string, mixed>  $fm
     * @return ?list<string>
     */
    private function optionalStringList(array $fm, string $key): ?array
    {
        if (! array_key_exists($key, $fm)) {
            return null;
        }

        return $this->stringListFromMixed($fm[$key], $key);
    }

    /**
     * @param  array<string, mixed>  $fm
     * @param  array<int, string>  $keys
     */
    private function optionalStringListFromKeys(array $fm, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $fm)) {
                continue;
            }

            $value = $fm[$key];
            if ($value === null) {
                return null;
            }

            return $this->stringListFromMixed($value, $key);
        }

        return null;
    }

    /**
     * @return ?list<string>
     */
    private function stringListFromMixed(mixed $value, string $key): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf('Frontmatter key "%s" must be a list when set.', $key));
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException(sprintf('Frontmatter key "%s" must be a list of strings.', $key));
            }
            $out[] = $item;
        }

        return $out;
    }
}
