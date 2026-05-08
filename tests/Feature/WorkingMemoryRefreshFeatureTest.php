<?php

namespace Tests\Feature;

use App\Jobs\ConsolidateWorkingMemory;
use App\Models\Project;
use App\Models\User;
use App\Support\TagSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use ReflectionClass;
use Tests\TestCase;

class WorkingMemoryRefreshFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['features.working_memory_ui' => true]);
    }

    public function test_global_refresh_queues_consolidated_job_with_global_scope(): void
    {
        Queue::fake();
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('memory.show'))
            ->post(route('working-memory.refresh.global'));

        $response->assertRedirect(route('memory.show'));
        $response->assertSessionHas('success', 'Queued consolidated rebuild for global working memory.');

        Queue::assertPushed(
            ConsolidateWorkingMemory::class,
            fn (ConsolidateWorkingMemory $job): bool => $this->matchesJobScope($job, $user->id, 'global', 'global')
        );
    }

    public function test_project_refresh_queues_consolidated_job_for_owner(): void
    {
        Queue::fake();
        /** @var User $owner */
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $response = $this->actingAs($owner)
            ->from(route('projects.show', $project))
            ->post(route('working-memory.refresh.project', $project));

        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('success', 'Queued consolidated rebuild for project working memory.');

        Queue::assertPushed(
            ConsolidateWorkingMemory::class,
            fn (ConsolidateWorkingMemory $job): bool => $this->matchesJobScope($job, $owner->id, 'project', (string) $project->getKey())
        );
    }

    public function test_project_refresh_unauthorized_returns_forbidden_and_does_not_queue_job(): void
    {
        Queue::fake();
        /** @var User $owner */
        $owner = User::factory()->create();
        /** @var User $intruder */
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $this->actingAs($intruder)
            ->post(route('working-memory.refresh.project', $project))
            ->assertForbidden();

        Queue::assertNotPushed(ConsolidateWorkingMemory::class);
    }

    public function test_tag_refresh_with_signed_context_normalizes_and_queues_tag_scope(): void
    {
        Queue::fake();
        /** @var User $user */
        $user = User::factory()->create();
        $signedUrl = $this->signedTagRefreshUrl('ai-notes');

        $response = $this->actingAs($user)
            ->from(route('idea.stream', ['tag' => 'ai_notes']))
            ->post($signedUrl, [
                'tag' => '  AI-Notes  ',
            ]);

        $response->assertRedirect(route('idea.stream', ['tag' => 'ai_notes']));
        $response->assertSessionHas('success', 'Queued consolidated rebuild for tag working memory.');

        Queue::assertPushed(
            ConsolidateWorkingMemory::class,
            fn (ConsolidateWorkingMemory $job): bool => $this->matchesJobScope($job, $user->id, 'tag', 'ai_notes')
        );
    }

    public function test_tag_refresh_rejects_posted_tag_mismatch_with_signed_context_and_does_not_queue_job(): void
    {
        Queue::fake();
        /** @var User $user */
        $user = User::factory()->create();
        $signedUrl = $this->signedTagRefreshUrl('ai');

        $response = $this->actingAs($user)
            ->from(route('idea.stream', ['tag' => 'ai']))
            ->post($signedUrl, [
                'tag' => 'ops',
            ]);

        $response->assertRedirect(route('idea.stream', ['tag' => 'ai']));
        $response->assertSessionHas('error', 'Invalid tag context for working memory refresh.');
        Queue::assertNotPushed(ConsolidateWorkingMemory::class);
    }

    public function test_tag_refresh_unsigned_request_is_forbidden_and_does_not_queue_job(): void
    {
        Queue::fake();
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('working-memory.refresh.tag'), [
                'tag' => 'ai',
            ])
            ->assertForbidden();

        Queue::assertNotPushed(ConsolidateWorkingMemory::class);
    }

    public function test_tag_refresh_invalid_signature_is_forbidden_and_does_not_queue_job(): void
    {
        Queue::fake();
        /** @var User $user */
        $user = User::factory()->create();
        $signedUrl = $this->signedTagRefreshUrl('ai');
        $tamperedUrl = (string) preg_replace('/signature=[^&]+/', 'signature=invalid', $signedUrl);

        $this->assertNotSame($signedUrl, $tamperedUrl);

        $this->actingAs($user)
            ->post($tamperedUrl, [
                'tag' => 'ai',
            ])
            ->assertForbidden();

        Queue::assertNotPushed(ConsolidateWorkingMemory::class);
    }

    public function test_tag_refresh_missing_signed_tag_context_rejected_and_does_not_queue_job(): void
    {
        Queue::fake();
        /** @var User $user */
        $user = User::factory()->create();
        $signedUrl = URL::signedRoute('working-memory.refresh.tag');

        $response = $this->actingAs($user)
            ->from(route('idea.stream', ['tag' => 'ai']))
            ->post($signedUrl, [
                'tag' => 'ai',
            ]);

        $response->assertRedirect(route('idea.stream', ['tag' => 'ai']));
        $response->assertSessionHas('error', 'Invalid tag context for working memory refresh.');
        Queue::assertNotPushed(ConsolidateWorkingMemory::class);
    }

    public function test_tag_refresh_accepts_slash_containing_tag_from_post_body(): void
    {
        Queue::fake();
        /** @var User $user */
        $user = User::factory()->create();
        $signedUrl = $this->signedTagRefreshUrl('product/ai');

        $response = $this->actingAs($user)
            ->from(route('idea.stream', ['tag' => 'product_ai']))
            ->post($signedUrl, [
                'tag' => 'Product/AI',
            ]);

        $response->assertRedirect(route('idea.stream', ['tag' => 'product_ai']));
        $response->assertSessionHas('success', 'Queued consolidated rebuild for tag working memory.');

        Queue::assertPushed(
            ConsolidateWorkingMemory::class,
            fn (ConsolidateWorkingMemory $job): bool => $this->matchesJobScope($job, $user->id, 'tag', 'product_ai')
        );
    }

    public function test_guest_redirected_to_login(): void
    {
        Queue::fake();

        $response = $this->post(route('working-memory.refresh.global'));

        $response->assertRedirect(route('login'));
        Queue::assertNotPushed(ConsolidateWorkingMemory::class);
    }

    public function test_refresh_endpoints_return_not_found_when_working_memory_ui_feature_disabled(): void
    {
        Queue::fake();
        config(['features.working_memory_ui' => false]);

        /** @var User $user */
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $signedTagUrl = $this->signedTagRefreshUrl('ai');

        $this->actingAs($user)
            ->post(route('working-memory.refresh.global'))
            ->assertNotFound();

        $this->actingAs($user)
            ->post(route('working-memory.refresh.project', $project))
            ->assertNotFound();

        $this->actingAs($user)
            ->post($signedTagUrl, ['tag' => 'ai'])
            ->assertNotFound();

        Queue::assertNotPushed(ConsolidateWorkingMemory::class);
    }

    private function matchesJobScope(ConsolidateWorkingMemory $job, int $userId, string $scopeType, string $scopeKey): bool
    {
        $reflection = new ReflectionClass($job);

        return $reflection->getProperty('userId')->getValue($job) === $userId
            && $reflection->getProperty('scopeType')->getValue($job) === $scopeType
            && $reflection->getProperty('scopeKey')->getValue($job) === $scopeKey;
    }

    private function signedTagRefreshUrl(string $tag): string
    {
        return URL::signedRoute('working-memory.refresh.tag', ['tag' => TagSlug::from($tag)]);
    }
}
