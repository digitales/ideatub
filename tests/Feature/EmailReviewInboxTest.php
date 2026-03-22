<?php

namespace Tests\Feature;

use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Models\InboxItem;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailReviewInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_shows_sender_classification_controls_for_email_review_items(): void
    {
        $user = User::factory()->create();
        ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertSee('Allow sender', false);
        $response->assertSee('Ignore sender', false);
        $response->assertSee('Extra process sender', false);
        $response->assertSee(route('inbox.email-review.action', $inbox), false);
    }

    public function test_non_email_review_items_do_not_show_sender_classification_controls(): void
    {
        $user = User::factory()->create();
        InboxItem::factory()->create([
            'user_id' => $user->id,
            'generator_type' => 'weekly_revisit',
            'dedupe_key' => 'weekly-'.$user->id,
        ]);

        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertDontSee('Allow sender', false);
    }

    public function test_user_can_mark_review_sender_as_allow_creates_sender_rule_and_completes_inbox(): void
    {
        $this->fakeOpenRouterForThoughtCapture();

        $user = User::factory()->create();
        ['imported' => $imported, 'inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'allow',
        ]);

        $response->assertRedirect(route('inbox.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'newsletter@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $inbox->refresh();
        $imported->refresh(); // must refresh before asserting on thought_id
        $this->assertSame('done', $inbox->status);
        $this->assertNotNull($inbox->actioned_at);
        $this->assertSame(1, Thought::query()->where('source', 'email')->count());
        $this->assertNotNull($imported->thought_id);
        $this->assertSame('imported', $imported->processing_status);
    }

    public function test_user_can_mark_review_sender_as_ignore_creates_sender_rule(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'ignore',
        ]);

        $response->assertRedirect(route('inbox.index'));

        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'newsletter@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $inbox->refresh();
        $this->assertSame('done', $inbox->status);
        Bus::assertNotDispatched(ProcessExtraEmailResearch::class);
    }

    public function test_user_can_mark_review_sender_as_extra_process_creates_sender_rule_without_dispatching_research_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'extra_process',
        ]);

        $response->assertRedirect(route('inbox.index'));

        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'newsletter@example.com',
            'action' => EmailSenderRule::ACTION_EXTRA_PROCESS,
        ]);

        $inbox->refresh();
        $this->assertSame('done', $inbox->status);
        $this->assertSame(0, Thought::query()->count());
        Bus::assertNotDispatched(ProcessExtraEmailResearch::class);
    }

    public function test_email_review_action_works_for_captured_inbound_email_source(): void
    {
        $this->fakeOpenRouterForThoughtCapture();
        Bus::fake();

        $user = User::factory()->create();
        $captured = CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'msg-review-'.uniqid(),
            'sender_email' => 'postmark-sender@example.com',
            'subject' => 'Hello',
            'body_text' => 'Body',
            'received_at' => now(),
            'rule_action' => 'review',
            'rule_email' => 'postmark-sender@example.com',
            'thought_id' => null,
            'research_thought_id' => null,
            'review_inbox_item_id' => null,
            'processing_status' => 'review_queued',
            'processing_metadata_json' => null,
        ]);

        $inbox = InboxItem::query()->create([
            'user_id' => $user->id,
            'generator_type' => 'email_sender_review',
            'title' => 'Review sender',
            'body' => 'Body',
            'status' => 'pending',
            'snoozed_until' => null,
            'generated_at' => now(),
            'actioned_at' => null,
            'dedupe_key' => 'email_sender_review:captured_inbound_email:'.$captured->id,
            'source_data' => [
                'stored_email_type' => 'captured_inbound_email',
                'stored_email_id' => $captured->id,
                'sender_email' => 'postmark-sender@example.com',
                'rule_action' => 'review',
            ],
        ]);

        $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'allow',
        ]);

        $response->assertRedirect(route('inbox.index'));

        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'postmark-sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $captured->refresh();
        $this->assertNotNull($captured->thought_id);
        $this->assertSame('imported', $captured->processing_status);
        $this->assertSame(1, Thought::query()->where('source', 'email')->count());
    }

    public function test_user_cannot_apply_email_review_action_to_another_users_inbox_item(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($owner);

        $response = $this->actingAs($other)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'allow',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('email_sender_rules', [
            'user_id' => $other->id,
        ]);
    }

    public function test_sender_classification_updates_existing_rule_row(): void
    {
        $this->fakeOpenRouterForThoughtCapture();
        $user = User::factory()->create();
        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'newsletter@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'allow',
        ]);

        $this->assertSame(1, EmailSenderRule::query()->where('user_id', $user->id)->where('sender_email', 'newsletter@example.com')->count());
        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'newsletter@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
    }

    public function test_sender_classification_rejects_source_data_sender_that_does_not_match_stored_email_sender(): void
    {
        $user = User::factory()->create();
        ['imported' => $imported, 'inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $inbox->update([
            'source_data' => [
                'stored_email_type' => 'imported_email',
                'stored_email_id' => $imported->id,
                'sender_email' => 'Wrong Sender <WRONG@example.com>',
                'rule_action' => 'review',
            ],
        ]);

        $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'allow',
        ]);

        $response->assertRedirect(route('inbox.index'));
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'wrong@example.com',
        ]);
        $this->assertDatabaseCount('inbox_item_actions', 0);

        $inbox->refresh();
        $imported->refresh();

        $this->assertSame('pending', $inbox->status);
        $this->assertNull($inbox->actioned_at);
        $this->assertNull($imported->processing_metadata_json['email_review_triage'] ?? null);
    }

    public function test_repeat_classification_post_does_not_mutate_completed_review_item_again(): void
    {
        $this->fakeOpenRouterForThoughtCapture();
        $user = User::factory()->create();
        ['imported' => $imported, 'inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $firstResponse = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'allow',
        ]);

        $firstResponse->assertRedirect(route('inbox.index'));

        $imported->refresh();
        $firstMetadata = $imported->processing_metadata_json;
        $firstClassifiedAt = $firstMetadata['email_review_triage']['classified_at'] ?? null;

        $this->assertNotNull($firstClassifiedAt);
        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'newsletter@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
        $this->assertSame(1, $inbox->actions()->where('action_type', 'email_sender_classify')->count());

        $secondResponse = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'ignore',
        ]);

        $secondResponse->assertRedirect(route('inbox.index'));

        $inbox->refresh();
        $imported->refresh();

        $this->assertSame('done', $inbox->status);
        $this->assertSame(1, $inbox->actions()->where('action_type', 'email_sender_classify')->count());
        $this->assertSame($firstMetadata, $imported->processing_metadata_json);
        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'newsletter@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
    }

    public function test_repeat_classification_post_returns_already_handled_flash_message(): void
    {
        $this->fakeOpenRouterForThoughtCapture();
        $user = User::factory()->create();
        ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'allow',
        ])->assertRedirect(route('inbox.index'));

        $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'ignore',
        ]);

        $response->assertRedirect(route('inbox.index'));
        $response->assertSessionHas('success', 'Sender classification was already handled.');
    }

    public function test_stale_non_actionable_review_post_returns_already_handled_flash_message(): void
    {
        $user = User::factory()->create();
        ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $inbox->update([
            'snoozed_until' => now()->addDay(),
        ]);

        $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'allow',
        ]);

        $response->assertRedirect(route('inbox.index'));
        $response->assertSessionHas('success', 'Sender classification was already handled.');
        $this->assertDatabaseCount('email_sender_rules', 0);
        $this->assertDatabaseCount('inbox_item_actions', 0);
    }

    public function test_imported_email_review_save_as_thought_creates_email_thought_and_links_thought_id(): void
    {
        $this->fakeOpenRouterForThoughtCapture();

        $user = User::factory()->create();
        ['imported' => $imported, 'inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'save_thought',
        ]);

        $response->assertRedirect(route('inbox.index'));
        $response->assertSessionHas('success');

        $this->assertSame(1, Thought::query()->where('source', 'email')->count());
        $thought = Thought::query()->where('source', 'email')->first();
        $this->assertNotNull($thought);
        $this->assertSame($imported->id, $thought->source_metadata['imported_email_id'] ?? null);
        $this->assertSame('review', $thought->source_metadata['sender_rule_action'] ?? null);

        $imported->refresh();
        $this->assertSame($thought->id, $imported->thought_id);
        $this->assertSame('imported', $imported->processing_status);

        $inbox->refresh();
        $this->assertSame('done', $inbox->status);
        $this->assertNotNull($inbox->actioned_at);
        $this->assertSame(1, $inbox->actions()->where('action_type', 'save_as_thought')->count());
    }

    public function test_captured_inbound_email_review_save_as_thought_creates_email_thought_and_links_thought_id(): void
    {
        $this->fakeOpenRouterForThoughtCapture();

        $user = User::factory()->create();
        $captured = CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'msg-save-thought-'.uniqid(),
            'sender_email' => 'postmark-sender@example.com',
            'subject' => 'Hello',
            'body_text' => 'Body from stored email',
            'received_at' => now(),
            'rule_action' => 'review',
            'rule_email' => 'postmark-sender@example.com',
            'thought_id' => null,
            'research_thought_id' => null,
            'review_inbox_item_id' => null,
            'processing_status' => 'review_queued',
            'processing_metadata_json' => null,
        ]);

        $inbox = InboxItem::query()->create([
            'user_id' => $user->id,
            'generator_type' => 'email_sender_review',
            'title' => 'Review sender',
            'body' => 'Inbox body should not be the thought content',
            'status' => 'pending',
            'snoozed_until' => null,
            'generated_at' => now(),
            'actioned_at' => null,
            'dedupe_key' => 'email_sender_review:captured_inbound_email:'.$captured->id,
            'source_data' => [
                'stored_email_type' => 'captured_inbound_email',
                'stored_email_id' => $captured->id,
                'sender_email' => 'postmark-sender@example.com',
                'rule_action' => 'review',
            ],
        ]);

        $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'save_thought',
        ]);

        $response->assertRedirect(route('inbox.index'));
        $response->assertSessionHas('success');

        $this->assertSame(1, Thought::query()->where('source', 'email')->count());
        $thought = Thought::query()->where('source', 'email')->first();
        $this->assertNotNull($thought);
        $this->assertSame($captured->id, $thought->source_metadata['captured_inbound_email_id'] ?? null);
        $this->assertSame($captured->message_id, $thought->source_metadata['message_id'] ?? null);
        $this->assertSame('review', $thought->source_metadata['sender_rule_action'] ?? null);
        $this->assertStringContainsString('Body from stored email', $thought->content);

        $captured->refresh();
        $this->assertSame($thought->id, $captured->thought_id);
        $this->assertSame('imported', $captured->processing_status);

        $inbox->refresh();
        $this->assertSame('done', $inbox->status);
    }

    public function test_repeated_email_review_save_as_thought_does_not_duplicate_thoughts(): void
    {
        $this->fakeOpenRouterForThoughtCapture();

        $user = User::factory()->create();
        ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'save_thought',
        ])->assertRedirect(route('inbox.index'));
        $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'save_thought',
        ])->assertRedirect(route('inbox.index'));

        $this->assertSame(1, Thought::query()->where('source', 'email')->count());
        $inbox->refresh();
        $this->assertSame(1, $inbox->actions()->where('action_type', 'save_as_thought')->count());
    }

    public function test_repeated_captured_inbound_email_review_save_thought_action_does_not_duplicate_thoughts(): void
    {
        $this->fakeOpenRouterForThoughtCapture();

        $user = User::factory()->create();
        $captured = CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'msg-save-thought-repeat-'.uniqid(),
            'sender_email' => 'postmark-sender@example.com',
            'subject' => 'Repeat save',
            'body_text' => 'Stored captured inbound body',
            'received_at' => now(),
            'rule_action' => 'review',
            'rule_email' => 'postmark-sender@example.com',
            'thought_id' => null,
            'research_thought_id' => null,
            'review_inbox_item_id' => null,
            'processing_status' => 'review_queued',
            'processing_metadata_json' => null,
        ]);

        $inbox = InboxItem::query()->create([
            'user_id' => $user->id,
            'generator_type' => 'email_sender_review',
            'title' => 'Review sender',
            'body' => 'Save this through the review action flow',
            'status' => 'pending',
            'snoozed_until' => null,
            'generated_at' => now(),
            'actioned_at' => null,
            'dedupe_key' => 'email_sender_review:captured_inbound_email:'.$captured->id,
            'source_data' => [
                'stored_email_type' => 'captured_inbound_email',
                'stored_email_id' => $captured->id,
                'sender_email' => 'postmark-sender@example.com',
                'rule_action' => 'review',
            ],
        ]);

        $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'save_thought',
        ])->assertRedirect(route('inbox.index'));

        $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'save_thought',
        ])->assertRedirect(route('inbox.index'));

        $this->assertSame(1, Thought::query()->where('source', 'email')->count());

        $captured->refresh();
        $inbox->refresh();

        $this->assertNotNull($captured->thought_id);
        $this->assertSame(1, $inbox->actions()->where('action_type', 'save_as_thought')->count());
    }

    public function test_user_cannot_save_another_users_email_review_as_thought(): void
    {
        $this->fakeOpenRouterForThoughtCapture();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($owner);

        $response = $this->actingAs($other)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'save_thought',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Thought::query()->count());
    }

    public function test_classified_at_uses_same_utc_timestamp_as_inbox_action_flow(): void
    {
        $this->fakeOpenRouterForThoughtCapture();
        $originalTimezone = config('app.timezone');
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');
        Carbon::setTestNow(Carbon::parse('2026-03-21 18:45:12', 'America/New_York'));

        try {
            $user = User::factory()->create();
            ['imported' => $imported, 'inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

            $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
                'action' => 'allow',
            ]);

            $response->assertRedirect(route('inbox.index'));

            $imported->refresh();
            $action = $inbox->actions()->where('action_type', 'email_sender_classify')->sole();
            $rawActionCreatedAt = DB::table('inbox_item_actions')->where('id', $action->id)->value('created_at');

            // The classify timestamp in metadata matches the InboxItemAction row's created_at.
            // Note: inbox_items.actioned_at is now the thought-creation timestamp (from saveReviewedEmailAsThought).
            // Note: inbox_items.actioned_at is now the thought-creation timestamp (from saveReviewedEmailAsThought),
            // distinct from the classify timestamp stored in InboxItemAction metadata.
            $this->assertSame(
                Carbon::createFromFormat('Y-m-d H:i:s', $rawActionCreatedAt, 'UTC')->toIso8601String(),
                $imported->processing_metadata_json['email_review_triage']['classified_at'] ?? null
            );
            $inbox->refresh();
            $this->assertNotNull($inbox->actioned_at);
        } finally {
            Carbon::setTestNow();
            config(['app.timezone' => $originalTimezone]);
            date_default_timezone_set($originalTimezone ?? 'UTC');
        }
    }

    public function test_allow_action_shows_partial_success_flash_when_thought_creation_fails(): void
    {
        // Simulate thought creation failing with HTTP 500 from OpenRouter
        config(['services.openrouter.api_key' => 'test-key']);
        Http::fake([
            'https://openrouter.ai/api/v1/embeddings' => Http::response([], 500),
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([], 500),
        ]);

        $user = User::factory()->create();
        ['imported' => $imported, 'inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'allow',
        ]);

        $response->assertRedirect(route('inbox.index'));
        // Sender rule is saved — this part succeeded
        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
        // Inbox item is done (was marked done by applySenderClassification)
        $inbox->refresh();
        $this->assertSame('done', $inbox->status);
        // Partial-success flash
        $response->assertSessionHas('success', 'Sender rule saved. Could not import email as a thought.');
        // No thought created
        $this->assertSame(0, Thought::query()->count());
    }

    /**
     * @return array{imported: ImportedEmail, inbox: InboxItem, mailAccount: MailAccount}
     */
    private function createImportedEmailReviewFixture(User $user): array
    {
        $mailAccount = MailAccount::factory()->create(['user_id' => $user->id]);

        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $mailAccount->id,
            'mail_sync_run_id' => null,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-'.uniqid(),
            'direction' => 'inbound',
            'subject' => 'Needs review',
            'body_text' => 'Hello',
            'processing_status' => 'review_queued',
            'rule_action' => 'review',
            'rule_email' => 'newsletter@example.com',
            'thought_id' => null,
        ]);

        $inbox = InboxItem::query()->create([
            'user_id' => $user->id,
            'generator_type' => 'email_sender_review',
            'title' => 'Review sender: newsletter@example.com',
            'body' => 'This message needs sender review: Needs review',
            'status' => 'pending',
            'snoozed_until' => null,
            'generated_at' => now(),
            'actioned_at' => null,
            'dedupe_key' => 'email_sender_review:imported_email:'.$imported->id,
            'source_data' => [
                'stored_email_type' => 'imported_email',
                'stored_email_id' => $imported->id,
                'sender_email' => 'newsletter@example.com',
                'rule_action' => 'review',
            ],
        ]);

        return [
            'imported' => $imported,
            'inbox' => $inbox,
            'mailAccount' => $mailAccount,
        ];
    }

    private function fakeOpenRouterForThoughtCapture(): void
    {
        config(['services.openrouter.api_key' => 'test-key']);
        Http::fake([
            'https://openrouter.ai/api/v1/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.1)]],
            ], 200),
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'type' => 'note',
                        'tags' => [],
                        'people' => [],
                        'action_items' => [],
                    ])]],
                ],
            ], 200),
        ]);
    }
}
