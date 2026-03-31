<?php

namespace Tests\Feature;

use App\Jobs\ProcessExtraEmailResearch;
use App\Jobs\RunResearchRun;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\ResearchRun;
use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Models\User;
use App\Services\Research\ResearchSkillManager;
use App\Services\ResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class EmailResearchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeEmailThought(User $user, array $overrides = []): Thought
    {
        return Thought::factory()->create(array_merge([
            'user_id' => $user->id,
            'source' => 'email',
        ], $overrides));
    }

    private function attachImportedEmail(User $user, Thought $thought): ImportedEmail
    {
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        // Use a real Thought ID to satisfy any FK constraint on research_thought_id.
        $priorResearchThought = Thought::factory()->create(['user_id' => $user->id]);

        $email = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'test-msg-'.uniqid(),
            'direction' => 'received',
            'subject' => 'Test newsletter',
            'body_text' => 'Test body.',
            'from_json' => [['email' => 'news@example.com', 'name' => 'News']],
            'processing_status' => 'research_completed',
            'thought_id' => $thought->id,
            'research_thought_id' => $priorResearchThought->id,
            'failure_count' => 1,
            'failure_reason' => 'prior failure',
        ]);

        return $email;
    }

    // -----------------------------------------------------------------------
    // ideaResearch
    // -----------------------------------------------------------------------

    public function test_idea_research_queues_run_job_for_email_thought(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        app(ResearchSkillManager::class)->create($user, [
            'name' => 'Default',
            'is_default' => true,
        ]);
        $thought = $this->makeEmailThought($user);

        $response = $this->actingAs($user)
            ->post(route('emails.idea-research', $thought));

        $response->assertRedirect();

        $this->assertDatabaseHas('research_runs', [
            'idea_thought_id' => $thought->id,
            'user_id' => $user->id,
            'status' => 'queued',
            'source' => 'email',
        ]);

        $runId = (int) ResearchRun::query()->where('idea_thought_id', $thought->id)->value('id');

        Queue::assertPushed(RunResearchRun::class, function (RunResearchRun $job) use ($runId): bool {
            return $job->researchRunId === $runId;
        });

        $thought->refresh();
        $this->assertTrue((bool) ($thought->metadata['research_pending'] ?? false));
    }

    public function test_idea_research_clears_research_pending_when_queueing_fails(): void
    {
        $user = User::factory()->create();
        $thought = $this->makeEmailThought($user);

        $this->mock(ResearchService::class, function (MockInterface $mock) use ($thought): void {
            $mock->shouldReceive('queueResearchRunForIdea')
                ->once()
                ->withArgs(function (...$args) use ($thought): bool {
                    [$queuedIdea, $source] = $args;
                    $researchSkillId = $args[2] ?? null;

                    return $queuedIdea->is($thought)
                        && $source === 'email'
                        && $researchSkillId === null;
                })
                ->andThrow(new \RuntimeException('Queue unavailable'));
        });

        $response = $this->actingAs($user)
            ->post(route('emails.idea-research', $thought));

        $response->assertRedirect();
        $thought->refresh();
        $this->assertFalse((bool) ($thought->metadata['research_pending'] ?? false));
    }

    public function test_idea_research_requires_authentication(): void
    {
        $thought = Thought::factory()->create(['source' => 'email']);

        $this->post(route('emails.idea-research', $thought))
            ->assertRedirect(route('login'));
    }

    public function test_idea_research_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = $this->makeEmailThought($owner);

        $this->actingAs($other)
            ->post(route('emails.idea-research', $thought))
            ->assertForbidden();
    }

    public function test_idea_research_rejects_non_email_thought(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'source' => 'web']);

        $this->actingAs($user)
            ->post(route('emails.idea-research', $thought))
            ->assertUnprocessable();
    }

    // -----------------------------------------------------------------------
    // newsletterResearch
    // -----------------------------------------------------------------------

    public function test_newsletter_research_requeues_for_imported_email(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $thought = $this->makeEmailThought($user, [
            'source_metadata' => ['newsletter_research' => ['status' => 'research_completed']],
        ]);
        $email = $this->attachImportedEmail($user, $thought);
        $previousResearchThoughtId = $email->research_thought_id;
        $this->assertNotNull($previousResearchThoughtId);

        $staleSummary = ThoughtLinkSummary::factory()->create([
            'user_id' => $user->id,
            'source_thought_id' => $thought->id,
            'parent_research_thought_id' => $previousResearchThoughtId,
        ]);
        $otherParentThought = Thought::factory()->create(['user_id' => $user->id]);
        $unrelatedSummary = ThoughtLinkSummary::factory()->create([
            'user_id' => $user->id,
            'source_thought_id' => $thought->id,
            'parent_research_thought_id' => $otherParentThought->id,
        ]);

        $response = $this->actingAs($user)
            ->post(route('emails.newsletter-research', $thought));

        $response->assertRedirect();
        Queue::assertPushed(ProcessExtraEmailResearch::class, function ($job) use ($email) {
            return $job->importedEmailId === $email->id && $job->capturedInboundEmailId === null;
        });
        $email->refresh();
        $this->assertSame('research_queued', $email->processing_status);
        $this->assertNull($email->research_thought_id);
        // Spec: failure_count and failure_reason are intentionally not cleared on re-trigger.
        $this->assertSame(1, $email->failure_count);
        $this->assertSame('prior failure', $email->failure_reason);
        $thought->refresh();
        $this->assertNull(data_get($thought->source_metadata, 'newsletter_research'));
        $this->assertDatabaseMissing('thought_link_summaries', ['id' => $staleSummary->id]);
        $this->assertDatabaseHas('thought_link_summaries', ['id' => $unrelatedSummary->id]);
    }

    public function test_newsletter_research_requeues_for_captured_inbound_email(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $thought = $this->makeEmailThought($user, [
            'source_metadata' => ['newsletter_research' => ['status' => 'research_failed']],
        ]);
        // Use a real Thought ID to satisfy any FK constraint on research_thought_id.
        $priorResearch = Thought::factory()->create(['user_id' => $user->id]);
        $captured = CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'cap-msg-'.uniqid(),
            'sender_email' => 'news@example.com',
            'subject' => 'Postmark newsletter',
            'body_text' => 'Body text.',
            'processing_status' => 'research_failed',
            'thought_id' => $thought->id,
            'research_thought_id' => $priorResearch->id,
        ]);
        $staleSummary = ThoughtLinkSummary::factory()->create([
            'user_id' => $user->id,
            'source_thought_id' => $thought->id,
            'parent_research_thought_id' => $priorResearch->id,
        ]);
        $otherParentThought = Thought::factory()->create(['user_id' => $user->id]);
        $unrelatedSummary = ThoughtLinkSummary::factory()->create([
            'user_id' => $user->id,
            'source_thought_id' => $thought->id,
            'parent_research_thought_id' => $otherParentThought->id,
        ]);

        $this->actingAs($user)
            ->post(route('emails.newsletter-research', $thought))
            ->assertRedirect();

        Queue::assertPushed(ProcessExtraEmailResearch::class, function ($job) use ($captured) {
            return $job->capturedInboundEmailId === $captured->id && $job->importedEmailId === null;
        });
        $captured->refresh();
        $this->assertSame('research_queued', $captured->processing_status);
        $this->assertNull($captured->research_thought_id);
        $thought->refresh();
        $this->assertNull(data_get($thought->source_metadata, 'newsletter_research'));
        $this->assertDatabaseMissing('thought_link_summaries', ['id' => $staleSummary->id]);
        $this->assertDatabaseHas('thought_link_summaries', ['id' => $unrelatedSummary->id]);
    }

    public function test_newsletter_research_returns_404_when_no_email_row(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $thought = $this->makeEmailThought($user);

        // No ImportedEmail or CapturedInboundEmail linked.
        $this->actingAs($user)
            ->post(route('emails.newsletter-research', $thought))
            ->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_newsletter_research_requires_authentication(): void
    {
        $thought = Thought::factory()->create(['source' => 'email']);

        $this->post(route('emails.newsletter-research', $thought))
            ->assertRedirect(route('login'));
    }

    public function test_newsletter_research_rejects_non_owner(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = $this->makeEmailThought($owner);

        $this->actingAs($other)
            ->post(route('emails.newsletter-research', $thought))
            ->assertForbidden();
    }

    public function test_newsletter_research_rejects_non_email_thought(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'source' => 'web']);

        $this->actingAs($user)
            ->post(route('emails.newsletter-research', $thought))
            ->assertUnprocessable();
    }
}
