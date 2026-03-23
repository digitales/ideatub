<?php

namespace Tests\Feature;

use App\Events\IdeaResearchRequested;
use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
            'source'  => 'email',
        ], $overrides));
    }

    private function attachImportedEmail(User $user, Thought $thought): ImportedEmail
    {
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        // Use a real Thought ID to satisfy any FK constraint on research_thought_id.
        $priorResearchThought = Thought::factory()->create(['user_id' => $user->id]);

        $email = ImportedEmail::query()->create([
            'user_id'            => $user->id,
            'mail_account_id'    => $account->id,
            'provider'           => 'fastmail',
            'provider_message_id'=> 'test-msg-'.uniqid(),
            'direction'          => 'received',
            'subject'            => 'Test newsletter',
            'body_text'          => 'Test body.',
            'from_json'          => [['email' => 'news@example.com', 'name' => 'News']],
            'processing_status'  => 'research_completed',
            'thought_id'         => $thought->id,
            'research_thought_id'=> $priorResearchThought->id,
            'failure_count'      => 1,
            'failure_reason'     => 'prior failure',
        ]);

        return $email;
    }

    // -----------------------------------------------------------------------
    // ideaResearch
    // -----------------------------------------------------------------------

    public function test_idea_research_dispatches_event_for_email_thought(): void
    {
        Event::fake();

        $user    = User::factory()->create();
        $thought = $this->makeEmailThought($user);

        $response = $this->actingAs($user)
            ->post(route('emails.idea-research', $thought));

        $response->assertRedirect();
        Event::assertDispatched(IdeaResearchRequested::class, function ($event) use ($thought) {
            return $event->idea->id === $thought->id && $event->source === 'email';
        });
        $thought->refresh();
        $this->assertTrue((bool) ($thought->metadata['research_pending'] ?? false));
    }

    public function test_idea_research_requires_authentication(): void
    {
        $thought = Thought::factory()->create(['source' => 'email']);

        $this->post(route('emails.idea-research', $thought))
            ->assertRedirect(route('login'));
    }

    public function test_idea_research_rejects_non_owner(): void
    {
        Event::fake();

        $owner   = User::factory()->create();
        $other   = User::factory()->create();
        $thought = $this->makeEmailThought($owner);

        $this->actingAs($other)
            ->post(route('emails.idea-research', $thought))
            ->assertForbidden();
    }

    public function test_idea_research_rejects_non_email_thought(): void
    {
        Event::fake();

        $user    = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'source' => 'web']);

        $this->actingAs($user)
            ->post(route('emails.idea-research', $thought))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // newsletterResearch
    // -----------------------------------------------------------------------

    public function test_newsletter_research_requeues_for_imported_email(): void
    {
        Queue::fake();

        $user    = User::factory()->create();
        $thought = $this->makeEmailThought($user, [
            'source_metadata' => ['newsletter_research' => ['status' => 'research_completed']],
        ]);
        $email = $this->attachImportedEmail($user, $thought);

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
    }

    public function test_newsletter_research_requeues_for_captured_inbound_email(): void
    {
        Queue::fake();

        $user    = User::factory()->create();
        $thought = $this->makeEmailThought($user);
        $priorResearch = Thought::factory()->create(['user_id' => $user->id]);
        $captured = CapturedInboundEmail::query()->create([
            'user_id'            => $user->id,
            'message_id'         => 'cap-msg-'.uniqid(),
            'sender_email'       => 'news@example.com',
            'subject'            => 'Postmark newsletter',
            'body_text'          => 'Body text.',
            'processing_status'  => 'research_failed',
            'thought_id'         => $thought->id,
            'research_thought_id'=> $priorResearch->id,
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
    }

    public function test_newsletter_research_returns_404_when_no_email_row(): void
    {
        Queue::fake();

        $user    = User::factory()->create();
        $thought = $this->makeEmailThought($user);

        // No ImportedEmail or CapturedInboundEmail linked.
        $this->actingAs($user)
            ->post(route('emails.newsletter-research', $thought))
            ->assertNotFound();
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

        $owner   = User::factory()->create();
        $other   = User::factory()->create();
        $thought = $this->makeEmailThought($owner);

        $this->actingAs($other)
            ->post(route('emails.newsletter-research', $thought))
            ->assertForbidden();
    }

    public function test_newsletter_research_rejects_non_email_thought(): void
    {
        Queue::fake();

        $user    = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'source' => 'web']);

        $this->actingAs($user)
            ->post(route('emails.newsletter-research', $thought))
            ->assertForbidden();
    }
}
