<?php

namespace Tests\Feature;

use App\Jobs\ReconcileIgnoredSenderThoughtVisibility;
use App\Models\CapturedInboundEmail;
use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileIgnoredSenderThoughtVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_hides_imported_email_backed_thought_for_ignored_sender(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $mailAccount = MailAccount::factory()->create(['user_id' => $user->id]);

        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $mailAccount->id,
            'mail_sync_run_id' => null,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-'.uniqid(),
            'direction' => 'inbound',
            'subject' => 'Subj',
            'body_text' => 'Hi',
            'processing_status' => 'imported',
            'rule_action' => 'allow',
            'rule_email' => 'newsletter@example.com',
            'from_json' => [['email' => 'newsletter@example.com', 'name' => 'News']],
            'thought_id' => null,
        ]);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => $imported->id,
            ],
            'is_visible_in_stream' => true,
            'visibility_reason' => null,
        ]);

        $imported->update(['thought_id' => $thought->id]);

        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'newsletter@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $job = new ReconcileIgnoredSenderThoughtVisibility($user->id, 'newsletter@example.com');
        app()->call([$job, 'handle']);

        $thought->refresh();
        $this->assertFalse($thought->is_visible_in_stream);
        $this->assertSame(Thought::VISIBILITY_REASON_IGNORED_SENDER, $thought->visibility_reason);
    }

    public function test_job_hides_captured_inbound_email_backed_thought_for_ignored_sender(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();

        $captured = CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'msg-cap-'.uniqid(),
            'sender_email' => 'postmark-sender@example.com',
            'subject' => 'Hello',
            'body_text' => 'Body',
            'received_at' => now(),
            'rule_action' => 'allow',
            'rule_email' => 'postmark-sender@example.com',
            'thought_id' => null,
            'research_thought_id' => null,
            'review_inbox_item_id' => null,
            'processing_status' => 'imported',
            'processing_metadata_json' => null,
        ]);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => [
                'captured_inbound_email_id' => $captured->id,
                'from' => 'postmark-sender@example.com',
            ],
            'is_visible_in_stream' => true,
            'visibility_reason' => null,
        ]);

        $captured->update(['thought_id' => $thought->id]);

        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'postmark-sender@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $job = new ReconcileIgnoredSenderThoughtVisibility($user->id, 'postmark-sender@example.com');
        app()->call([$job, 'handle']);

        $thought->refresh();
        $this->assertFalse($thought->is_visible_in_stream);
        $this->assertSame(Thought::VISIBILITY_REASON_IGNORED_SENDER, $thought->visibility_reason);
    }

    public function test_job_restores_thought_when_sender_rule_changes_from_ignore_to_another_action(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $mailAccount = MailAccount::factory()->create(['user_id' => $user->id]);

        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $mailAccount->id,
            'mail_sync_run_id' => null,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-'.uniqid(),
            'direction' => 'inbound',
            'subject' => 'Subj',
            'body_text' => 'Hi',
            'processing_status' => 'imported',
            'rule_action' => 'ignore',
            'rule_email' => 'newsletter@example.com',
            'thought_id' => null,
        ]);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => ['imported_email_id' => $imported->id],
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $imported->update(['thought_id' => $thought->id]);

        $rule = EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'newsletter@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $job = new ReconcileIgnoredSenderThoughtVisibility($user->id, 'newsletter@example.com');
        app()->call([$job, 'handle']);

        $thought->refresh();
        $this->assertTrue($thought->is_visible_in_stream);
        $this->assertNull($thought->visibility_reason);
    }

    public function test_job_restores_thought_when_sender_rule_is_deleted(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $mailAccount = MailAccount::factory()->create(['user_id' => $user->id]);

        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $mailAccount->id,
            'mail_sync_run_id' => null,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-'.uniqid(),
            'direction' => 'inbound',
            'subject' => 'Subj',
            'body_text' => 'Hi',
            'processing_status' => 'imported',
            'rule_action' => 'ignore',
            'rule_email' => 'gone@example.com',
            'thought_id' => null,
        ]);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => ['imported_email_id' => $imported->id],
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $imported->update(['thought_id' => $thought->id]);

        $job = new ReconcileIgnoredSenderThoughtVisibility($user->id, 'gone@example.com');
        app()->call([$job, 'handle']);

        $thought->refresh();
        $this->assertTrue($thought->is_visible_in_stream);
        $this->assertNull($thought->visibility_reason);
    }

    public function test_job_is_idempotent_when_run_twice(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $mailAccount = MailAccount::factory()->create(['user_id' => $user->id]);

        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $mailAccount->id,
            'mail_sync_run_id' => null,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-'.uniqid(),
            'direction' => 'inbound',
            'subject' => 'Subj',
            'body_text' => 'Hi',
            'processing_status' => 'imported',
            'rule_action' => 'allow',
            'rule_email' => 'newsletter@example.com',
            'thought_id' => null,
        ]);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => ['imported_email_id' => $imported->id],
            'is_visible_in_stream' => true,
            'visibility_reason' => null,
        ]);

        $imported->update(['thought_id' => $thought->id]);

        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'newsletter@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $job = new ReconcileIgnoredSenderThoughtVisibility($user->id, 'newsletter@example.com');
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $thought->refresh();
        $this->assertFalse($thought->is_visible_in_stream);
        $this->assertSame(Thought::VISIBILITY_REASON_IGNORED_SENDER, $thought->visibility_reason);
    }

    public function test_job_does_not_unhide_thought_hidden_for_another_reason(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $mailAccount = MailAccount::factory()->create(['user_id' => $user->id]);

        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $mailAccount->id,
            'mail_sync_run_id' => null,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-'.uniqid(),
            'direction' => 'inbound',
            'subject' => 'Subj',
            'body_text' => 'Hi',
            'processing_status' => 'imported',
            'rule_action' => 'allow',
            'rule_email' => 'newsletter@example.com',
            'thought_id' => null,
        ]);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => ['imported_email_id' => $imported->id],
            'is_visible_in_stream' => false,
            'visibility_reason' => 'manual_hide',
        ]);

        $imported->update(['thought_id' => $thought->id]);

        $job = new ReconcileIgnoredSenderThoughtVisibility($user->id, 'newsletter@example.com');
        app()->call([$job, 'handle']);

        $thought->refresh();
        $this->assertFalse($thought->is_visible_in_stream);
        $this->assertSame('manual_hide', $thought->visibility_reason);
    }

    public function test_job_skips_thoughts_whose_sender_cannot_be_resolved_safely(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => [],
            'is_visible_in_stream' => true,
            'visibility_reason' => null,
        ]);

        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'anyone@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $job = new ReconcileIgnoredSenderThoughtVisibility($user->id, 'anyone@example.com');
        app()->call([$job, 'handle']);

        $thought->refresh();
        $this->assertTrue($thought->is_visible_in_stream);
        $this->assertNull($thought->visibility_reason);
    }

    public function test_job_no_ops_when_sender_policy_feature_flag_is_disabled(): void
    {
        config(['services.email_sender_policy.enabled' => false]);

        $user = User::factory()->create();
        $mailAccount = MailAccount::factory()->create(['user_id' => $user->id]);

        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $mailAccount->id,
            'mail_sync_run_id' => null,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-'.uniqid(),
            'direction' => 'inbound',
            'subject' => 'Subj',
            'body_text' => 'Hi',
            'processing_status' => 'imported',
            'rule_action' => 'allow',
            'rule_email' => 'newsletter@example.com',
            'thought_id' => null,
        ]);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => ['imported_email_id' => $imported->id],
            'is_visible_in_stream' => true,
            'visibility_reason' => null,
        ]);

        $imported->update(['thought_id' => $thought->id]);

        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'newsletter@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $job = new ReconcileIgnoredSenderThoughtVisibility($user->id, 'newsletter@example.com');
        app()->call([$job, 'handle']);

        $thought->refresh();
        $this->assertTrue($thought->is_visible_in_stream);
        $this->assertNull($thought->visibility_reason);
    }
}
