<?php

namespace Tests\Unit\Services\Learning;

use App\Models\LearningLesson;
use App\Models\LearningProject;
use App\Models\LearningQuiz;
use App\Models\LearningQuizQuestion;
use App\Models\LearningResearchDocument;
use App\Models\User;
use App\Services\Learning\LearningContentPaths;
use App\Services\Learning\LearningMarkdownFrontmatterParser;
use App\Services\Learning\LearningSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LearningSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = storage_path('framework/testing/learning-sync-'.uniqid('', true));
        File::ensureDirectoryExists($this->fixtureRoot.'/research');
        File::ensureDirectoryExists($this->fixtureRoot.'/curriculum/lessons');
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureRoot) && is_dir($this->fixtureRoot)) {
            File::deleteDirectory($this->fixtureRoot);
        }
        parent::tearDown();
    }

    public function test_initial_import_returns_counts(): void
    {
        $this->writeResearch('alpha.md', 'alpha-doc', 'Alpha', "# Alpha\n");
        $this->writeLesson('one.md', 'lesson-one', 'Lesson One', "# One\n", quizYaml: null);

        $project = $this->makeProject();
        $service = $this->makeService();

        $result = $service->sync($project);

        $this->assertSame(['research' => 1, 'lessons' => 1], $result);
        $this->assertSame(1, LearningResearchDocument::query()->count());
        $this->assertSame(1, LearningLesson::query()->count());
    }

    public function test_second_sync_is_idempotent_without_duplicate_rows(): void
    {
        $this->writeResearch('alpha.md', 'alpha-doc', 'Alpha', "# Alpha\n");
        $this->writeLesson('one.md', 'lesson-one', 'Lesson One', "# One\n");

        $project = $this->makeProject();
        $service = $this->makeService();

        $first = $service->sync($project);
        $second = $service->sync($project);

        $this->assertSame($first, $second);
        $this->assertSame(1, LearningResearchDocument::query()->count());
        $this->assertSame(1, LearningLesson::query()->count());

        $lessonIds = LearningLesson::query()->pluck('id')->sort()->values()->all();
        $service->sync($project->fresh());
        $this->assertSame($lessonIds, LearningLesson::query()->pluck('id')->sort()->values()->all());
    }

    public function test_prune_removes_rows_when_files_removed(): void
    {
        $this->writeResearch('keep.md', 'keep-me', 'Keep', "# K\n");
        $this->writeResearch('gone.md', 'remove-me', 'Remove', "# R\n");

        $project = $this->makeProject();
        $service = $this->makeService();
        $service->sync($project);

        $this->assertSame(2, LearningResearchDocument::query()->count());

        unlink($this->fixtureRoot.'/research/gone.md');
        $service->sync($project->fresh());

        $this->assertSame(1, LearningResearchDocument::query()->count());
        $this->assertSame('keep-me', LearningResearchDocument::query()->value('slug'));
    }

    public function test_prune_removes_lesson_when_file_removed(): void
    {
        file_put_contents(
            $this->fixtureRoot.'/curriculum/lessons/stay.md',
            $this->lessonMarkdown('stay', 'Stay', "# S\n")
        );
        file_put_contents(
            $this->fixtureRoot.'/curriculum/lessons/go.md',
            $this->lessonMarkdown('go', 'Go', "# G\n")
        );

        $project = $this->makeProject();
        $service = $this->makeService();
        $service->sync($project);

        $this->assertSame(2, LearningLesson::query()->count());

        unlink($this->fixtureRoot.'/curriculum/lessons/go.md');
        $service->sync($project->fresh());

        $this->assertSame(1, LearningLesson::query()->count());
        $this->assertSame('stay', LearningLesson::query()->value('slug'));
    }

    public function test_content_version_increments_when_lesson_body_changes(): void
    {
        $path = $this->fixtureRoot.'/curriculum/lessons/one.md';
        file_put_contents($path, $this->lessonMarkdown('v1', 'V1', "# First\n"));

        $project = $this->makeProject();
        $service = $this->makeService();
        $service->sync($project);

        $lesson = LearningLesson::query()->where('slug', 'v1')->first();
        $this->assertNotNull($lesson);
        $this->assertSame(1, $lesson->content_version);

        file_put_contents($path, $this->lessonMarkdown('v1', 'V1', "# Second\n"));
        $service->sync($project->fresh());

        $lesson->refresh();
        $this->assertSame(2, $lesson->content_version);
    }

    public function test_quiz_is_replaced_and_questions_are_ordered(): void
    {
        $quiz = <<<'YAML'
quiz:
  title: Check
  passingScore: 80
  questions:
    - prompt: Pick two
      options: ["2", "3"]
      correctOption: "3"
    - prompt: Pick zero
      options: ["a", "b"]
      correctOption: 0
YAML;

        file_put_contents(
            $this->fixtureRoot.'/curriculum/lessons/q.md',
            $this->lessonMarkdown('quiz-lesson', 'Quiz Lesson', "# Body\n", extraYaml: "\n".$quiz)
        );

        $project = $this->makeProject();
        $service = $this->makeService();
        $service->sync($project);

        $lesson = LearningLesson::query()->where('slug', 'quiz-lesson')->firstOrFail();
        $quizModel = LearningQuiz::query()->where('learning_lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame('Check', $quizModel->title);
        $this->assertSame(80, $quizModel->passing_score);

        $questions = LearningQuizQuestion::query()->where('learning_quiz_id', $quizModel->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $questions);
        $this->assertSame(['2', '3'], $questions[0]->options);
        $this->assertSame(1, $questions[0]->correct_option_index);
        $this->assertSame(['a', 'b'], $questions[1]->options);
        $this->assertSame(0, $questions[1]->correct_option_index);
    }

    public function test_related_research_alias_maps_to_related_research_slugs_column(): void
    {
        file_put_contents(
            $this->fixtureRoot.'/curriculum/lessons/related.md',
            <<<'MD'
---
slug: related-lesson
title: Related Lesson
relatedResearch:
  - architecture-overview
---

# Body

MD
        );

        $project = $this->makeProject();
        $service = $this->makeService();
        $service->sync($project);

        $lesson = LearningLesson::query()->where('slug', 'related-lesson')->firstOrFail();
        $this->assertSame(['architecture-overview'], $lesson->related_research_slugs);
    }

    private function makeProject(): LearningProject
    {
        $user = User::factory()->create();

        return LearningProject::query()->create([
            'user_id' => $user->id,
            'slug' => 'sync-test-'.uniqid(),
            'title' => 'Sync Test Project',
            'content_root' => $this->fixtureRoot,
            'source_url' => null,
        ]);
    }

    private function makeService(): LearningSyncService
    {
        return new LearningSyncService(
            new LearningMarkdownFrontmatterParser,
            new LearningContentPaths
        );
    }

    private function writeResearch(string $filename, string $slug, string $title, string $body): void
    {
        file_put_contents(
            $this->fixtureRoot.'/research/'.$filename,
            $this->researchMarkdown($slug, $title, $body)
        );
    }

    private function writeLesson(string $filename, string $slug, string $title, string $body, ?string $quizYaml = ''): void
    {
        file_put_contents(
            $this->fixtureRoot.'/curriculum/lessons/'.$filename,
            $this->lessonMarkdown($slug, $title, $body, extraYaml: $quizYaml ?? '')
        );
    }

    private function researchMarkdown(string $slug, string $title, string $body): string
    {
        return <<<MD
---
slug: {$slug}
title: {$title}
---

{$body}
MD;
    }

    private function lessonMarkdown(string $slug, string $title, string $body, string $extraYaml = ''): string
    {
        return <<<MD
---
slug: {$slug}
title: {$title}{$extraYaml}
---

{$body}
MD;
    }
}
