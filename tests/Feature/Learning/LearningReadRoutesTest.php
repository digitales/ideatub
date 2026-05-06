<?php

namespace Tests\Feature\Learning;

use App\Models\LearningLesson;
use App\Models\LearningProject;
use App\Models\LearningResearchDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningReadRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_is_redirected_from_learning_routes(): void
    {
        $this->get(route('learn.projects.index'))
            ->assertRedirect(route('login'));
    }

    public function test_owner_can_view_learning_read_routes(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $project = LearningProject::query()->create([
            'user_id' => $user->id,
            'slug' => 'learn-read-test',
            'title' => 'Learn Read Test',
            'content_root' => '/tmp/unused-for-read-tests',
            'source_url' => null,
        ]);

        LearningResearchDocument::query()->create([
            'learning_project_id' => $project->id,
            'slug' => 'architecture-overview',
            'title' => 'Architecture Overview',
            'summary' => 'Summary',
            'category' => 'architecture',
            'source_url' => null,
            'body_markdown' => "# Hello\n\nBody.",
            'synced_at' => now(),
        ]);

        LearningLesson::query()->create([
            'learning_project_id' => $project->id,
            'slug' => 'orient-the-system',
            'title' => 'Orient the System',
            'stage' => 'Foundations',
            'difficulty' => 'Intro',
            'order' => 1,
            'summary' => null,
            'goals' => null,
            'related_research_slugs' => null,
            'body_markdown' => "# Lesson\n\nHello.",
            'content_version' => 1,
            'synced_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get(route('learn.projects.index'))->assertOk();
        $this->get(route('learn.projects.show', $project))->assertOk();
        $this->get(route('learn.research.index', $project))->assertOk();
        $this->get(route('learn.research.show', [$project, 'architecture-overview']))->assertOk();
        $this->get(route('learn.lessons.show', [$project, 'orient-the-system']))->assertOk();
    }

    public function test_non_owner_cannot_view_learning_project(): void
    {
        /** @var User $owner */
        $owner = User::factory()->createOne();
        /** @var User $other */
        $other = User::factory()->createOne();

        $project = LearningProject::query()->create([
            'user_id' => $owner->id,
            'slug' => 'private-learn',
            'title' => 'Private Learn',
            'content_root' => '/tmp/unused-for-read-tests',
            'source_url' => null,
        ]);

        $this->actingAs($other);

        $this->get(route('learn.projects.show', $project))->assertForbidden();
    }
}
