<?php

namespace Tests\Feature\Learning;

use App\Models\LearningLesson;
use App\Models\LearningProject;
use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_owner_can_capture_learning_artifact_to_thoughts(): void
    {
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            // ThoughtCaptureService uses embed + extractMetadata once for non-chunked capture.
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        /** @var User $owner */
        $owner = User::factory()->createOne();

        $project = LearningProject::query()->create([
            'user_id' => $owner->id,
            'slug' => 'capture-proj',
            'title' => 'Capture Proj',
            'content_root' => '/tmp/unused',
            'source_url' => null,
        ]);

        $lesson = LearningLesson::query()->create([
            'learning_project_id' => $project->id,
            'slug' => 'lesson-a',
            'title' => 'Lesson A',
            'stage' => null,
            'difficulty' => null,
            'order' => 1,
            'summary' => null,
            'goals' => null,
            'related_research_slugs' => null,
            'body_markdown' => "# A\n",
            'content_version' => 1,
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($owner)->post(route('learn.lessons.capture', [$project, $lesson->slug]), [
            '_token' => csrf_token(),
            'artifact_type' => 'takeaway',
            'content' => 'Key takeaway: sync is transactional.',
        ]);

        $response->assertRedirect(route('learn.lessons.show', [$project, $lesson->slug]));
        $response->assertSessionHas('success');

        $thought = Thought::query()->where('user_id', $owner->id)->latest('created_at')->first();
        $this->assertNotNull($thought);

        $meta = is_array($thought->source_metadata) ? $thought->source_metadata : [];
        $this->assertSame('learning', $thought->source);
        $this->assertSame($project->slug, $meta['learning_project_slug'] ?? null);
        $this->assertSame($lesson->slug, $meta['lesson_slug'] ?? null);
        $this->assertSame('takeaway', $meta['artifact_type'] ?? null);
        $this->assertIsString($meta['lesson_url'] ?? null);
    }

    public function test_non_owner_cannot_capture_for_project(): void
    {
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('embed')->never();
            $mock->shouldReceive('extractMetadata')->never();
        });

        /** @var User $owner */
        $owner = User::factory()->createOne();
        /** @var User $other */
        $other = User::factory()->createOne();

        $project = LearningProject::query()->create([
            'user_id' => $owner->id,
            'slug' => 'capture-private',
            'title' => 'Capture Private',
            'content_root' => '/tmp/unused',
            'source_url' => null,
        ]);

        $lesson = LearningLesson::query()->create([
            'learning_project_id' => $project->id,
            'slug' => 'lesson-b',
            'title' => 'Lesson B',
            'stage' => null,
            'difficulty' => null,
            'order' => 1,
            'summary' => null,
            'goals' => null,
            'related_research_slugs' => null,
            'body_markdown' => "# B\n",
            'content_version' => 1,
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($other)->post(route('learn.lessons.capture', [$project, $lesson->slug]), [
            '_token' => csrf_token(),
            'artifact_type' => 'takeaway',
            'content' => 'Should not save.',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Thought::query()->where('user_id', $other->id)->count());
    }
}
