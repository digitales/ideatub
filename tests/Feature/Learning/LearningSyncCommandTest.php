<?php

namespace Tests\Feature\Learning;

use App\Models\LearningLesson;
use App\Models\LearningProject;
use App\Models\LearningResearchDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LearningSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = storage_path('framework/testing/learning-sync-cmd-'.uniqid('', true));
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

    #[Test]
    public function sync_command_imports_content_for_project_owner(): void
    {
        $this->writeResearch('alpha.md', 'alpha-doc', 'Alpha', "# Alpha\n");
        $this->writeLesson('one.md', 'lesson-one', 'Lesson One', "# One\n");

        $owner = User::factory()->create();
        $project = LearningProject::query()->create([
            'user_id' => $owner->id,
            'slug' => 'cmd-sync-'.uniqid(),
            'title' => 'Cmd Sync Project',
            'content_root' => $this->fixtureRoot,
            'source_url' => null,
        ]);

        $this->artisan('learning:sync', [
            'project' => $project->id,
            '--user' => (string) $owner->id,
        ])
            ->assertExitCode(0)
            ->expectsOutput('Synced research: 1')
            ->expectsOutput('Synced lessons: 1');

        $this->assertSame(1, LearningResearchDocument::query()->count());
        $this->assertSame(1, LearningLesson::query()->count());
    }

    #[Test]
    public function sync_command_fails_when_user_does_not_own_project(): void
    {
        file_put_contents(
            $this->fixtureRoot.'/research/x.md',
            $this->researchMarkdown('x', 'X', "# X\n")
        );
        file_put_contents(
            $this->fixtureRoot.'/curriculum/lessons/y.md',
            $this->lessonMarkdown('y', 'Y', "# Y\n")
        );

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = LearningProject::query()->create([
            'user_id' => $owner->id,
            'slug' => 'cmd-mismatch-'.uniqid(),
            'title' => 'Mismatch',
            'content_root' => $this->fixtureRoot,
            'source_url' => null,
        ]);

        $this->artisan('learning:sync', [
            'project' => $project->id,
            '--user' => (string) $other->id,
        ])->assertExitCode(1);

        $this->assertSame(0, LearningResearchDocument::query()->count());
        $this->assertSame(0, LearningLesson::query()->count());
    }

    private function writeResearch(string $filename, string $slug, string $title, string $body): void
    {
        file_put_contents(
            $this->fixtureRoot.'/research/'.$filename,
            $this->researchMarkdown($slug, $title, $body)
        );
    }

    private function writeLesson(string $filename, string $slug, string $title, string $body): void
    {
        file_put_contents(
            $this->fixtureRoot.'/curriculum/lessons/'.$filename,
            $this->lessonMarkdown($slug, $title, $body)
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

    private function lessonMarkdown(string $slug, string $title, string $body): string
    {
        return <<<MD
---
slug: {$slug}
title: {$title}
---

{$body}
MD;
    }
}
