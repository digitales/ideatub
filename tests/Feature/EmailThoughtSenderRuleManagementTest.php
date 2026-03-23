<?php

namespace Tests\Feature;

use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailThoughtSenderRuleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['services.email_sender_policy.enabled' => true]);
    }

    public function test_quick_whitelist_creates_allow_rule_from_stored_sender_context(): void
    {
        $user = User::factory()->create();
        $thought = $this->createImportedEmailThought($user, 'Sender Name <sender@example.com>');

        $response = $this->actingAs($user)
            ->from(route('thoughts.show', $thought))
            ->post(route('thoughts.sender-rules.store', $thought), [
                'action' => EmailSenderRule::ACTION_ALLOW,
                'sender_email' => 'spoofed@example.com',
            ]);

        $response->assertRedirect(route('thoughts.show', $thought));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
        $this->assertDatabaseMissing('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'spoofed@example.com',
        ]);
    }

    public function test_whitelist_updates_a_non_allow_rule_to_allow(): void
    {
        $user = User::factory()->create();
        $thought = $this->createImportedEmailThought($user, 'sender@example.com');
        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_REVIEW,
        ]);

        $response = $this->actingAs($user)
            ->from(route('thoughts.show', $thought))
            ->post(route('thoughts.sender-rules.store', $thought), [
                'action' => EmailSenderRule::ACTION_ALLOW,
            ]);

        $response->assertRedirect(route('thoughts.show', $thought));
        $response->assertSessionHas('success');

        $this->assertDatabaseCount('email_sender_rules', 1);
        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
    }

    public function test_remove_from_whitelist_deletes_an_existing_allow_rule(): void
    {
        $user = User::factory()->create();
        $thought = $this->createImportedEmailThought($user, 'sender@example.com');
        $rule = EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $response = $this->actingAs($user)
            ->from(route('thoughts.show', $thought))
            ->delete(route('thoughts.sender-rules.destroy', $thought));

        $response->assertRedirect(route('thoughts.show', $thought));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('email_sender_rules', [
            'id' => $rule->id,
        ]);
    }

    public function test_full_rule_save_sets_ignore_review_and_extra_process(): void
    {
        $user = User::factory()->create();
        $thought = $this->createImportedEmailThought($user, 'sender@example.com');

        foreach ([
            EmailSenderRule::ACTION_IGNORE,
            EmailSenderRule::ACTION_REVIEW,
            EmailSenderRule::ACTION_EXTRA_PROCESS,
        ] as $action) {
            EmailSenderRule::query()->delete();

            $response = $this->actingAs($user)
                ->from(route('thoughts.show', $thought))
                ->post(route('thoughts.sender-rules.store', $thought), [
                    'action' => $action,
                ]);

            $response->assertRedirect(route('thoughts.show', $thought));
            $response->assertSessionHas('success');

            $this->assertDatabaseHas('email_sender_rules', [
                'user_id' => $user->id,
                'sender_email' => 'sender@example.com',
                'action' => $action,
            ]);
        }
    }

    public function test_all_upserts_go_through_same_post_endpoint_with_validated_action(): void
    {
        $user = User::factory()->create();
        $thought = $this->createImportedEmailThought($user, 'sender@example.com');

        $invalidResponse = $this->actingAs($user)
            ->from(route('thoughts.show', $thought))
            ->post(route('thoughts.sender-rules.store', $thought), [
                'action' => 'archive',
            ]);

        $invalidResponse->assertRedirect(route('thoughts.show', $thought));
        $invalidResponse->assertSessionHasErrors('action');
        $this->assertDatabaseCount('email_sender_rules', 0);

        $validResponse = $this->actingAs($user)
            ->from(route('thoughts.show', $thought))
            ->post(route('thoughts.sender-rules.store', $thought), [
                'action' => EmailSenderRule::ACTION_IGNORE,
            ]);

        $validResponse->assertRedirect(route('thoughts.show', $thought));
        $validResponse->assertSessionHas('success');
        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);
    }

    public function test_wrong_user_gets_403(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $thought = $this->createImportedEmailThought($owner, 'sender@example.com');

        $response = $this->actingAs($otherUser)
            ->post(route('thoughts.sender-rules.store', $thought), [
                'action' => EmailSenderRule::ACTION_ALLOW,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('email_sender_rules', 0);
    }

    public function test_wrong_user_gets_403_on_delete(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $thought = $this->createImportedEmailThought($owner, 'sender@example.com');
        $rule = EmailSenderRule::query()->create([
            'user_id' => $owner->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $response = $this->actingAs($otherUser)
            ->delete(route('thoughts.sender-rules.destroy', $thought));

        $response->assertForbidden();
        $this->assertDatabaseHas('email_sender_rules', [
            'id' => $rule->id,
            'user_id' => $owner->id,
            'sender_email' => 'sender@example.com',
        ]);
    }

    public function test_non_email_thought_gets_404(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'web',
            'content' => 'Not an email thought',
        ]);

        $response = $this->actingAs($user)
            ->post(route('thoughts.sender-rules.store', $thought), [
                'action' => EmailSenderRule::ACTION_ALLOW,
            ]);

        $response->assertNotFound();
        $this->assertDatabaseCount('email_sender_rules', 0);
    }

    public function test_non_email_thought_delete_gets_404(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'web',
            'content' => 'Not an email thought',
        ]);

        $response = $this->actingAs($user)
            ->delete(route('thoughts.sender-rules.destroy', $thought));

        $response->assertNotFound();
        $this->assertDatabaseCount('email_sender_rules', 0);
    }

    public function test_feature_flag_off_gets_404(): void
    {
        config(['services.email_sender_policy.enabled' => false]);

        $user = User::factory()->create();
        $thought = $this->createImportedEmailThought($user, 'sender@example.com');

        $response = $this->actingAs($user)
            ->post(route('thoughts.sender-rules.store', $thought), [
                'action' => EmailSenderRule::ACTION_ALLOW,
            ]);

        $response->assertNotFound();
        $this->assertDatabaseCount('email_sender_rules', 0);
    }

    public function test_feature_flag_off_delete_gets_404(): void
    {
        config(['services.email_sender_policy.enabled' => false]);

        $user = User::factory()->create();
        $thought = $this->createImportedEmailThought($user, 'sender@example.com');

        $response = $this->actingAs($user)
            ->delete(route('thoughts.sender-rules.destroy', $thought));

        $response->assertNotFound();
        $this->assertDatabaseCount('email_sender_rules', 0);
    }

    public function test_unresolved_sender_redirects_back_with_error_flash_and_does_not_mutate_rules(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'content' => 'Email without a usable sender',
            'source_metadata' => [
                'subject' => 'No sender available',
                'from' => [['name' => 'Sender Missing Email']],
            ],
        ]);

        $response = $this->actingAs($user)
            ->from(route('thoughts.show', $thought))
            ->post(route('thoughts.sender-rules.store', $thought), [
                'action' => EmailSenderRule::ACTION_ALLOW,
            ]);

        $response->assertRedirect(route('thoughts.show', $thought));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('email_sender_rules', 0);
    }

    public function test_unresolved_sender_delete_redirects_back_with_error_flash_and_does_not_delete_rule(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'content' => 'Email without a usable sender',
            'source_metadata' => [
                'subject' => 'No sender available',
                'from' => [['name' => 'Sender Missing Email']],
            ],
        ]);
        $rule = EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $response = $this->actingAs($user)
            ->from(route('thoughts.show', $thought))
            ->delete(route('thoughts.sender-rules.destroy', $thought));

        $response->assertRedirect(route('thoughts.show', $thought));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('email_sender_rules', [
            'id' => $rule->id,
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
    }

    private function createImportedEmailThought(User $user, string $from): Thought
    {
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'content' => 'Imported email thought body',
            'source_metadata' => [],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $importedEmail = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'imported-'.uniqid(),
            'direction' => 'received',
            'subject' => 'Imported subject',
            'body_text' => 'Imported body text',
            'from_json' => [
                [
                    'email' => $this->extractEmail($from),
                    'name' => str_contains($from, '<') ? 'Sender Name' : '',
                ],
            ],
            'processing_status' => 'imported',
            'thought_id' => $thought->id,
        ]);

        $thought->update([
            'source_metadata' => [
                'imported_email_id' => $importedEmail->id,
                'from' => $from,
            ],
        ]);

        return $thought->fresh();
    }

    private function extractEmail(string $from): string
    {
        if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $from, $matches) === 1) {
            return mb_strtolower($matches[1]);
        }

        return mb_strtolower($from);
    }
}
