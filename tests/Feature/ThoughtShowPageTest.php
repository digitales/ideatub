<?php

namespace Tests\Feature;

use App\Models\CapturedInboundEmail;
use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use App\Services\ThoughtCaptureService;
use App\Services\Video\VideoCaptureService;
use App\View\Presenters\Thoughts\ThoughtDetailPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ThoughtShowPageTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL_RESEARCH_PREVIEW_INTRO = 'Email research preview intro unique abc123.';

    private const EMAIL_RESEARCH_PREVIEW_SECTION_ONE = 'Section one body unique def456.';

    private const EMAIL_RESEARCH_PREVIEW_SECTION_TWO = 'Section two body unique ghi789.';

    private const EMAIL_RESEARCH_PREVIEW_SECTION_THREE = 'Section three must not appear jkl012.';

    private const EMAIL_RESEARCH_PREVIEW_EMPTY_ROOT_SECTION_BODY = 'Empty root section body unique mno345.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_demo_mode_obfuscates_detail_page_content_without_mutating_the_record(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'source' => 'web',
            'content' => 'Highly sensitive strategy note 42',
        ]);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'seed-123',
        ])->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertDontSee('Highly sensitive strategy note 42', false);
        $this->assertStringNotContainsString('Highly sensitive strategy note 42', $response->getContent());
        $response->assertSee('Demo mode enabled. Sensitive text is obfuscated.', false);

        $this->assertSame('Highly sensitive strategy note 42', $thought->fresh()->content);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        $normal = $this->actingAs($owner)->get(route('thoughts.show', $thought));
        $normal->assertSee('Highly sensitive strategy note 42', false);
    }

    public function test_demo_mode_thought_detail_does_not_expose_tag_edit_affordance(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Tagged thought demo detail body',
            'metadata' => ['tags' => ['alphademo', 'betademo']],
        ]);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'seed-detail-tags-demo',
        ])->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertDontSee('Edit tags', false);
        $response->assertDontSee('Add tag…', false);
        $this->assertStringNotContainsString('aria-label="Edit tags"', $response->getContent());

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        $normal = $this->actingAs($owner)->get(route('thoughts.show', $thought));
        $normal->assertOk();
        $normal->assertSee('Edit tags', false);
    }

    public function test_thought_detail_shows_content_edit_for_owner(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Unique detail edit body zed-4421',
            'source' => 'web',
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertStringContainsString('aria-label="Edit content"', $response->getContent());
    }

    public function test_demo_mode_thought_detail_does_not_expose_content_edit_affordance(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Demo detail body zed-1199',
            'source' => 'web',
            'metadata' => ['tags' => ['alphademo']],
        ]);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'seed-detail-content-edit-demo',
        ])->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertStringNotContainsString('aria-label="Edit content"', $response->getContent());

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        $normal = $this->actingAs($owner)->get(route('thoughts.show', $thought));
        $normal->assertOk();
        $this->assertStringContainsString('aria-label="Edit content"', $normal->getContent());
    }

    public function test_demo_mode_obfuscates_email_thought_subject_and_body_without_mutating_imported_rows(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Fallback thought body unique demo fb 77',
            'source' => 'email',
            'source_metadata' => ['subject' => 'Meta subject should not win'],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $owner->id]);
        $importedEmail = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-demo-mode-email-'.uniqid(),
            'provider_thread_id' => 'thread-demo',
            'direction' => 'received',
            'subject' => 'Demo mode email subject secret xyz888',
            'from_json' => [['email' => 'sender@example.com', 'name' => 'Sender']],
            'to_json' => [['email' => 'owner@example.com', 'name' => 'Owner']],
            'participants_json' => [],
            'sent_at' => now()->subMinute(),
            'received_at' => now(),
            'body_text' => 'Demo mode email body secret abc999',
            'processing_status' => 'imported',
            'thought_id' => $thought->id,
        ]);

        $thought->update([
            'source_metadata' => array_merge($thought->source_metadata ?? [], ['imported_email_id' => $importedEmail->id]),
        ]);
        $thought = $thought->fresh();
        $importedEmail = $importedEmail->fresh();

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'seed-email-demo',
        ])->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertDontSee('Demo mode email subject secret xyz888', false);
        $response->assertDontSee('Demo mode email body secret abc999', false);
        $response->assertSee('Demo mode enabled. Sensitive text is obfuscated.', false);
        $response->assertSee('Direction: received', false);
        $response->assertSee('Provider: fastmail', false);
        $response->assertSee('sender@example.com', false);

        $this->assertSame('Demo mode email subject secret xyz888', $importedEmail->fresh()->subject);
        $this->assertSame('Demo mode email body secret abc999', $importedEmail->fresh()->body_text);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        $normal = $this->actingAs($owner)->get(route('thoughts.show', $thought));
        $normal->assertSee('Demo mode email subject secret xyz888', false);
        $normal->assertSee('Demo mode email body secret abc999', false);
    }

    public function test_demo_mode_throwing_obfuscator_shows_placeholder_instead_of_raw_content(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'source' => 'web',
            'content' => 'Obfuscator throw marker secret content qqq111',
        ]);

        $real = app(DemoObfuscator::class);
        $mock = \Mockery::mock($real)->makePartial();
        $mock->shouldReceive('obfuscate')->andThrow(new \RuntimeException('simulated binding failure'));
        $this->app->instance(DemoObfuscator::class, $mock);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'seed-throw',
        ])->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertSee('Demo content hidden', false);
        $response->assertDontSee('Obfuscator throw marker secret content qqq111', false);
    }

    public function test_owner_can_view_thought_show_page(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Root thought body for detail view',
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertStatus(200);
        $response->assertSee('Root thought body for detail view', false);
    }

    public function test_shareable_document_detail_shows_create_share_link(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'research'],
            'content' => 'Research root for share block',
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));
        $response->assertOk();
        $response->assertSee(route('shared-research.index', ['create' => $thought->id], false), false);
        $response->assertSee('Create share link', false);
    }

    public function test_non_shareable_detail_hides_document_share_block(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'content' => 'Plain root for detail',
            'metadata' => null,
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));
        $response->assertOk();
        $response->assertDontSee(route('shared-research.index', ['create' => $thought->id], false), false);
        $response->assertDontSee('Create share link', false);
    }

    public function test_video_detail_hides_document_share_block(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'source' => 'web',
            'content' => 'Video root for share block test',
            'metadata' => [
                'type' => 'video',
                'video_url' => 'https://www.youtube.com/watch?v=detailShareBlockVid',
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));
        $response->assertOk();
        $response->assertDontSee(route('shared-research.index', ['create' => $thought->id], false), false);
    }

    public function test_completed_idea_detail_shows_reopen_control_posting_to_toggle_completed(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Completed idea on detail',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-02-01',
                'completed_at' => '2026-03-24T12:00:00+00:00',
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertSee('Mark as incomplete', false);
        $response->assertSee(route('ideas.toggle-completed', $thought), false);
        $response->assertSee('method="POST"', false);
        $response->assertSee('_method', false);
    }

    public function test_completed_idea_detail_shows_reopen_control_when_completed_at_is_present_but_flag_is_false(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Timestamp-only completed idea on detail',
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'logged_date' => '2025-02-01',
                'completed_at' => '2026-03-24T12:00:00+00:00',
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertSee('Mark as incomplete', false);
        $response->assertSee(route('ideas.toggle-completed', $thought), false);
    }

    public function test_incomplete_idea_detail_does_not_show_reopen_control(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Open idea on detail',
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'logged_date' => '2025-02-01',
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertDontSee('Mark as incomplete', false);
    }

    public function test_non_idea_detail_with_completed_flag_does_not_show_reopen_control(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Completed non-idea detail',
            'metadata' => [
                'type' => 'research',
                'completed' => true,
                'completed_at' => '2026-03-24T12:00:00+00:00',
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertDontSee('Mark as incomplete', false);
        $response->assertDontSee(route('ideas.toggle-completed', $thought), false);
    }

    public function test_reopening_completed_idea_from_detail_redirects_to_detail_clears_completion_and_updates_lists(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Reopen me from detail unique xyz789',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-03-01',
                'completed_at' => '2026-03-24T14:00:00+00:00',
            ],
        ]);

        $response = $this->actingAs($owner)
            ->from(route('thoughts.show', $thought))
            ->patch(route('ideas.toggle-completed', $thought));

        $response->assertRedirect(route('thoughts.show', $thought));

        $thought->refresh();
        $this->assertFalse((bool) ($thought->metadata['completed'] ?? false));
        $this->assertArrayNotHasKey('completed_at', $thought->metadata ?? []);

        $ideasPage = $this->actingAs($owner)->get(route('idea.ideas'));
        $ideasPage->assertOk();
        $ideasPage->assertSee('Reopen me from detail unique xyz789', false);

        $completedPage = $this->actingAs($owner)->get(route('idea.completed'));
        $completedPage->assertOk();
        $completedPage->assertDontSee('Reopen me from detail unique xyz789', false);
    }

    public function test_reopening_timestamp_only_completed_idea_from_detail_clears_completion_and_updates_lists(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Timestamp only reopen from detail unique tsOnly42',
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'logged_date' => '2025-03-01',
                'completed_at' => '2026-03-24T15:00:00+00:00',
            ],
        ]);

        $response = $this->actingAs($owner)
            ->from(route('thoughts.show', $thought))
            ->patch(route('ideas.toggle-completed', $thought));

        $response->assertRedirect(route('thoughts.show', $thought));

        $thought->refresh();
        $this->assertFalse((bool) ($thought->metadata['completed'] ?? false));
        $this->assertArrayNotHasKey('completed_at', $thought->metadata ?? []);

        $ideasPage = $this->actingAs($owner)->get(route('idea.ideas'));
        $ideasPage->assertOk();
        $ideasPage->assertSee('Timestamp only reopen from detail unique tsOnly42', false);

        $completedPage = $this->actingAs($owner)->get(route('idea.completed'));
        $completedPage->assertOk();
        $completedPage->assertDontSee('Timestamp only reopen from detail unique tsOnly42', false);
    }

    public function test_non_owner_cannot_toggle_completion_for_another_users_idea(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Protected completion toggle',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-03-01',
                'completed_at' => '2026-03-24T14:00:00+00:00',
            ],
        ]);

        $response = $this->actingAs($other)->patch(route('ideas.toggle-completed', $thought));

        $response->assertForbidden();

        $thought->refresh();
        $this->assertTrue((bool) ($thought->metadata['completed'] ?? false));
        $this->assertSame('2026-03-24T14:00:00+00:00', $thought->metadata['completed_at'] ?? null);
    }

    public function test_non_email_thought_detail_page_renders_markdown_content(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'web',
            'content' => "# Thought heading\n\nSome **bold** text.",
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertSee('<h1>Thought heading</h1>', false);
        $response->assertSee('<strong>bold</strong>', false);
    }

    public function test_chunked_document_sections_render_inline_as_markdown_and_stay_out_of_replies(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'web',
            'content' => "# Meeting notes\n\nRoot intro unique root-inline-901.",
        ]);
        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $thought->id,
            'embedding' => null,
            'source' => 'web',
            'content' => "## Summary\n\nSection body with **bold marker** unique section-inline-902.",
            'source_metadata' => [
                'section_index' => 1,
                'section_title' => 'Summary',
            ],
        ]);
        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $thought->id,
            'embedding' => null,
            'source' => 'web',
            'content' => "## Decisions\n\n- First decision unique section-inline-903.",
            'source_metadata' => [
                'section_index' => 2,
                'section_title' => 'Decisions',
            ],
        ]);
        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $thought->id,
            'embedding' => null,
            'source' => 'web',
            'content' => 'Actual follow-up reply unique reply-inline-904.',
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertSee('<h2>Summary</h2>', false);
        $response->assertSee('<strong>bold marker</strong>', false);
        $response->assertSee('First decision unique section-inline-903.', false);

        $xpath = $this->xpathFromResponse($response);
        $replySections = $xpath->query("//section[.//p[normalize-space(.)='Replies']]");

        $this->assertSame(1, $replySections->length);

        $replyText = trim($replySections->item(0)?->textContent ?? '');

        $this->assertStringContainsString('Actual follow-up reply unique reply-inline-904.', $replyText);
        $this->assertStringNotContainsString('Section body with bold marker unique section-inline-902.', $replyText);
        $this->assertStringNotContainsString('First decision unique section-inline-903.', $replyText);
    }

    public function test_other_user_cannot_view_thought_show_page(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Private root thought',
        ]);

        $response = $this->actingAs($other)->get(route('thoughts.show', $thought));

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_for_thought_show_page(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
        ]);

        $response = $this->get(route('thoughts.show', $thought));

        $response->assertRedirect(route('login'));
    }

    public function test_missing_thought_returns_404(): void
    {
        $owner = User::factory()->create();
        $missingId = '00000000-0000-0000-0000-000000000001';

        $response = $this->actingAs($owner)->get(route('thoughts.show', ['thought' => $missingId]));

        $response->assertNotFound();
    }

    public function test_replies_render_on_thought_show_page_and_page_includes_reply_label(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Thread root',
        ]);
        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $root->id,
            'embedding' => null,
            'content' => 'First reply in thread',
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $root));

        $response->assertStatus(200);
        $response->assertSee('First reply in thread', false);
        $response->assertSee('Reply', false);
    }

    public function test_demo_mode_obfuscates_reply_content_on_thought_detail_page_without_mutating_reply_records(): void
    {
        config(['services.demo_mode.enabled' => true]);

        $owner = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Parent detail root safe marker',
            'source' => 'web',
        ]);
        $reply = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $root->id,
            'content' => 'Reply secret marker zeta-444',
            'source' => 'web',
        ]);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'seed-reply-detail',
        ])->actingAs($owner)->get(route('thoughts.show', $root));

        $response->assertOk();
        $response->assertDontSee('Reply secret marker zeta-444', false);
        $response->assertSee('Reply', false);
        $this->assertSame('Reply secret marker zeta-444', $reply->fresh()->content);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $normal = $this->actingAs($owner)->get(route('thoughts.show', $root));
        $normal->assertOk();
        $normal->assertSee('Reply secret marker zeta-444', false);
    }

    public function test_email_thought_detail_page_shows_body_and_email_metadata(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Fallback thought body',
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Fallback subject',
            ],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $owner->id]);
        $importedEmail = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-1',
            'provider_thread_id' => 'thread-1',
            'direction' => 'received',
            'subject' => 'Imported subject',
            'from_json' => [['email' => 'sender@example.com', 'name' => 'Sender']],
            'to_json' => [['email' => 'owner@example.com', 'name' => 'Owner']],
            'participants_json' => [['role' => 'from', 'email' => 'sender@example.com', 'name' => 'Sender']],
            'sent_at' => now()->subMinute(),
            'received_at' => now(),
            'body_text' => 'Imported email body text',
            'processing_status' => 'imported',
            'thought_id' => $thought->id,
        ]);

        $thought->update([
            'source_metadata' => array_merge($thought->source_metadata ?? [], ['imported_email_id' => $importedEmail->id]),
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertSee('Imported email body text');
        $response->assertSee('Imported subject');
        $response->assertSee('sender@example.com');
        $response->assertSee('Direction: received');
    }

    public function test_email_thought_detail_sidebar_shows_provider_mailbox_thread_account_and_cc_lines(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Sidebar contract body',
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Should not win',
            ],
        ]);

        $account = MailAccount::factory()->create([
            'user_id' => $owner->id,
            'account_email' => 'synced-account@example.test',
        ]);
        $importedEmail = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-sidebar-contract',
            'provider_thread_id' => 'thread-sidebar-contract',
            'provider_mailbox_name' => 'Newsletters',
            'direction' => 'received',
            'subject' => 'Sidebar subject',
            'from_json' => [['email' => 'from@example.test', 'name' => 'From Person']],
            'to_json' => [['email' => 'to@example.test', 'name' => 'To Person']],
            'cc_json' => [['email' => 'cc@example.test', 'name' => 'Cc Person']],
            'participants_json' => [],
            'sent_at' => now()->subHour(),
            'received_at' => now(),
            'body_text' => 'Sidebar imported body',
            'processing_status' => 'imported',
            'thought_id' => $thought->id,
        ]);

        $thought->update([
            'source_metadata' => array_merge($thought->source_metadata ?? [], [
                'imported_email_id' => $importedEmail->id,
            ]),
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought->fresh()));

        $response->assertOk();
        $response->assertSee('Sidebar subject');
        $response->assertSee('Provider: fastmail');
        $response->assertSee('Mailbox: Newsletters');
        $response->assertSee('Thread ID: thread-sidebar-contract');
        $response->assertSee('Account: synced-account@example.test');
        $response->assertSee('Cc: Cc Person <cc@example.test>');
        $response->assertSee('Sent:', false);
        $response->assertSee('Received:', false);
    }

    public function test_email_thought_detail_research_preview_happy_path_shows_intro_two_sections_and_full_research_link(): void
    {
        [$owner, $emailThought, $researchThought] = $this->createEmailThoughtWithLinkedResearchPreviewFixture();

        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $this->assertEmailDetailResearchPreviewContract($response, $researchThought);
    }

    public function test_demo_mode_obfuscates_email_research_preview_without_mutating_research_records(): void
    {
        config(['services.demo_mode.enabled' => true]);

        [$owner, $emailThought, $researchThought] = $this->createEmailThoughtWithLinkedResearchPreviewFixture();
        $researchSections = $researchThought->comments()->orderBy('created_at')->get();

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'seed-email-preview',
        ])->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $response->assertOk();
        $response->assertSee('Research preview', false);
        $response->assertDontSee(self::EMAIL_RESEARCH_PREVIEW_INTRO, false);
        $response->assertDontSee(self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE, false);
        $response->assertDontSee(self::EMAIL_RESEARCH_PREVIEW_SECTION_TWO, false);
        $response->assertViewHas('thoughtDetail', function (ThoughtDetailPresenter $detail): bool {
            $preview = $detail->emailResearchPreview();
            $this->assertIsArray($preview);

            $combined = ($preview['root_html'] ?? '').implode('', $preview['section_html_chunks'] ?? []);
            $this->assertStringNotContainsString(self::EMAIL_RESEARCH_PREVIEW_INTRO, $combined);
            $this->assertStringNotContainsString(self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE, $combined);
            $this->assertStringNotContainsString(self::EMAIL_RESEARCH_PREVIEW_SECTION_TWO, $combined);

            return true;
        });
        $this->assertSame(self::EMAIL_RESEARCH_PREVIEW_INTRO, $researchThought->fresh()->content);
        $this->assertSame("## First\n\n".self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE, $researchSections[0]->fresh()->content);
        $this->assertSame("## Second\n\n".self::EMAIL_RESEARCH_PREVIEW_SECTION_TWO, $researchSections[1]->fresh()->content);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $normal = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));
        $normal->assertOk();
        $normal->assertSee(self::EMAIL_RESEARCH_PREVIEW_INTRO, false);
        $normal->assertSee(self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE, false);
        $normal->assertSee(self::EMAIL_RESEARCH_PREVIEW_SECTION_TWO, false);
    }

    public function test_email_thought_detail_omits_research_preview_and_cta_when_linked_research_is_missing(): void
    {
        $owner = User::factory()->create();
        $researchThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research later removed',
            'source' => 'web',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);
        $staleResearchId = $researchThought->id;

        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Email body with missing research link',
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Missing research',
                'research_thought_id' => $staleResearchId,
            ],
        ]);

        $this->attachImportedEmailWithResearchThoughtId($owner, $emailThought, $staleResearchId);

        $researchThought->delete();
        $this->assertDatabaseMissing('thoughts', ['id' => $staleResearchId]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought->fresh()));

        $this->assertEmailDetailOmitsResearchPreviewAndResearchCtas($response);
    }

    public function test_email_thought_detail_research_preview_shows_root_only_and_full_research_link(): void
    {
        [$owner, $emailThought, $researchThought] = $this->createEmailThoughtWithLinkedResearchContent(
            self::EMAIL_RESEARCH_PREVIEW_INTRO,
            []
        );

        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $response->assertOk();
        $this->assertEmailResearchPreviewViewModel($response, $researchThought, [
            'expect_intro_in_root_html' => true,
            'expect_section_plain_text' => [],
            'expect_absent_plain_text' => [self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE],
        ]);
        $response->assertViewHas('thoughtDetail', fn (ThoughtDetailPresenter $d) => $d->linkedResearchUrl() === route('idea.research.show', $researchThought));
        $response->assertSee('Research preview', false);
        $response->assertSee('View full research', false);
        $response->assertSee(self::EMAIL_RESEARCH_PREVIEW_INTRO, false);
        $response->assertDontSee(self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE, false);
    }

    public function test_email_thought_detail_research_preview_renders_when_root_empty_but_section_has_content(): void
    {
        [$owner, $emailThought, $researchThought] = $this->createEmailThoughtWithLinkedResearchContent(
            '',
            [
                "## Preview after empty root\n\n".self::EMAIL_RESEARCH_PREVIEW_EMPTY_ROOT_SECTION_BODY,
            ]
        );

        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $response->assertOk();
        $this->assertEmailResearchPreviewViewModel($response, $researchThought, [
            'expect_intro_in_root_html' => false,
            'expect_section_plain_text' => [self::EMAIL_RESEARCH_PREVIEW_EMPTY_ROOT_SECTION_BODY],
            'expect_absent_plain_text' => [],
        ]);
        $response->assertViewHas('thoughtDetail', fn (ThoughtDetailPresenter $d) => $d->linkedResearchUrl() === route('idea.research.show', $researchThought));
        $response->assertSee('Research preview', false);
        $response->assertSee('View full research', false);
        $response->assertSee(self::EMAIL_RESEARCH_PREVIEW_EMPTY_ROOT_SECTION_BODY, false);
    }

    public function test_email_thought_detail_omits_research_preview_when_linked_research_has_no_previewable_content(): void
    {
        [$owner, $emailThought] = $this->createEmailThoughtWithLinkedResearchContent('', []);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $this->assertEmailDetailOmitsResearchPreviewViewModel($response);
    }

    public function test_email_thought_detail_research_preview_shows_root_and_single_section_only(): void
    {
        [$owner, $emailThought, $researchThought] = $this->createEmailThoughtWithLinkedResearchContent(
            self::EMAIL_RESEARCH_PREVIEW_INTRO,
            [
                "## Only\n\n".self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE,
            ]
        );

        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $response->assertOk();
        $this->assertEmailResearchPreviewViewModel($response, $researchThought, [
            'expect_intro_in_root_html' => true,
            'expect_section_plain_text' => [self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE],
            'expect_absent_plain_text' => [self::EMAIL_RESEARCH_PREVIEW_SECTION_TWO],
        ]);
        $response->assertViewHas('thoughtDetail', fn (ThoughtDetailPresenter $d) => $d->linkedResearchUrl() === route('idea.research.show', $researchThought));
        $response->assertSee('Research preview', false);
        $response->assertSee('View full research', false);
        $response->assertSee(self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE, false);
        $response->assertDontSee(self::EMAIL_RESEARCH_PREVIEW_SECTION_TWO, false);
    }

    public function test_email_thought_detail_shows_research_preview_when_source_metadata_matches_imported_email_research_thought(): void
    {
        [$owner, $emailThought, $researchThought] = $this->createEmailThoughtWithLinkedImportedResearchPreviewFixture();

        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $response->assertOk();
        $response->assertViewHas('thoughtDetail', fn (ThoughtDetailPresenter $d) => $d->linkedResearchUrl() === route('idea.research.show', $researchThought));
        $this->assertNotNull($response->viewData('thoughtDetail')->emailResearchPreview());
        $response->assertSee('Research preview', false);
        $response->assertSee('View full research', false);
    }

    public function test_email_thought_detail_omits_view_research_link_when_research_thought_id_points_at_non_research_thought_for_same_user(): void
    {
        $owner = User::factory()->create();
        $nonResearchThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Regular idea, not research metadata',
            'source' => 'web',
            'metadata' => ['type' => 'idea', 'tags' => []],
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Email body with mistaken research id',
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Email with non-research link',
                'research_thought_id' => $nonResearchThought->id,
            ],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $owner->id]);
        $importedEmail = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-non-research-target',
            'provider_thread_id' => 'thread-non-research-target',
            'direction' => 'received',
            'subject' => 'Imported subject',
            'from_json' => [['email' => 'sender@example.com', 'name' => 'Sender']],
            'to_json' => [['email' => 'owner@example.com', 'name' => 'Owner']],
            'participants_json' => [['role' => 'from', 'email' => 'sender@example.com', 'name' => 'Sender']],
            'sent_at' => now()->subMinute(),
            'received_at' => now(),
            'body_text' => 'Body',
            'processing_status' => 'imported',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $nonResearchThought->id,
        ]);

        $emailThought->update([
            'source_metadata' => array_merge($emailThought->source_metadata ?? [], [
                'imported_email_id' => $importedEmail->id,
            ]),
        ]);

        $wouldBeHref = route('idea.research.show', $nonResearchThought);
        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $response->assertOk();
        $response->assertDontSee('View research', false);
        $response->assertDontSee($wouldBeHref, false);
    }

    public function test_email_thought_detail_omits_view_research_link_when_linked_research_thought_belongs_to_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $researchThought = Thought::factory()->create([
            'user_id' => $other->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Other user research',
            'source' => 'web',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Email body with cross-user research id',
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Email referencing foreign research',
                'research_thought_id' => $researchThought->id,
            ],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $owner->id]);
        $importedEmail = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-foreign-research',
            'provider_thread_id' => 'thread-foreign-research',
            'direction' => 'received',
            'subject' => 'Imported subject',
            'from_json' => [['email' => 'sender@example.com', 'name' => 'Sender']],
            'to_json' => [['email' => 'owner@example.com', 'name' => 'Owner']],
            'participants_json' => [['role' => 'from', 'email' => 'sender@example.com', 'name' => 'Sender']],
            'sent_at' => now()->subMinute(),
            'received_at' => now(),
            'body_text' => 'Body',
            'processing_status' => 'imported',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $researchThought->id,
        ]);

        $emailThought->update([
            'source_metadata' => array_merge($emailThought->source_metadata ?? [], [
                'imported_email_id' => $importedEmail->id,
            ]),
        ]);

        $researchHref = route('idea.research.show', $researchThought);
        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $this->assertEmailDetailOmitsResearchPreviewAndResearchCtas($response);
        $response->assertDontSee($researchHref, false);
    }

    public function test_email_thought_detail_omits_view_research_link_when_imported_and_captured_rows_disagree_even_if_source_metadata_matches_one_side(): void
    {
        $owner = User::factory()->create();
        $researchFromImported = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research from imported row',
            'source' => 'web',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);
        $researchFromCaptured = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research from captured row',
            'source' => 'web',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Email with split durable research ids',
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Split durable ids',
                'research_thought_id' => $researchFromImported->id,
            ],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $owner->id]);
        $importedEmail = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-split-durable-imported',
            'provider_thread_id' => 'thread-split-durable',
            'direction' => 'received',
            'subject' => 'Imported subject',
            'from_json' => [['email' => 'sender@example.com', 'name' => 'Sender']],
            'to_json' => [['email' => 'owner@example.com', 'name' => 'Owner']],
            'participants_json' => [['role' => 'from', 'email' => 'sender@example.com', 'name' => 'Sender']],
            'sent_at' => now()->subMinute(),
            'received_at' => now(),
            'body_text' => 'Body',
            'processing_status' => 'imported',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $researchFromImported->id,
        ]);

        CapturedInboundEmail::query()->create([
            'user_id' => $owner->id,
            'message_id' => 'captured-msg-split-durable-'.uniqid(),
            'sender_email' => 'captured@example.com',
            'subject' => 'Captured subject',
            'body_text' => 'Captured body',
            'received_at' => now(),
            'thought_id' => $emailThought->id,
            'research_thought_id' => $researchFromCaptured->id,
            'processing_status' => 'imported',
        ]);

        $emailThought->update([
            'source_metadata' => array_merge($emailThought->source_metadata ?? [], [
                'imported_email_id' => $importedEmail->id,
            ]),
        ]);

        $wouldBeHrefImported = route('idea.research.show', $researchFromImported);
        $wouldBeHrefCaptured = route('idea.research.show', $researchFromCaptured);
        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $response->assertOk();
        $response->assertDontSee('View research', false);
        $response->assertDontSee($wouldBeHrefImported, false);
        $response->assertDontSee($wouldBeHrefCaptured, false);
    }

    public function test_email_thought_detail_omits_view_research_link_when_source_metadata_and_imported_email_research_thought_disagree(): void
    {
        $owner = User::factory()->create();
        $researchFromMetadata = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research A',
            'source' => 'web',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);
        $researchFromStoredEmail = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research B',
            'source' => 'web',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Conflicting research ids',
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Conflict subject',
                'research_thought_id' => $researchFromMetadata->id,
            ],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $owner->id]);
        $importedEmail = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-conflict',
            'provider_thread_id' => 'thread-conflict',
            'direction' => 'received',
            'subject' => 'Imported',
            'from_json' => [['email' => 'a@example.com', 'name' => 'A']],
            'to_json' => [['email' => 'b@example.com', 'name' => 'B']],
            'participants_json' => [['role' => 'from', 'email' => 'a@example.com', 'name' => 'A']],
            'sent_at' => now()->subMinute(),
            'received_at' => now(),
            'body_text' => 'Body',
            'processing_status' => 'imported',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $researchFromStoredEmail->id,
        ]);

        $emailThought->update([
            'source_metadata' => array_merge($emailThought->source_metadata ?? [], [
                'imported_email_id' => $importedEmail->id,
            ]),
        ]);

        $metadataResearchHref = route('idea.research.show', $researchFromMetadata);
        $storedEmailResearchHref = route('idea.research.show', $researchFromStoredEmail);
        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $response->assertOk();
        $response->assertDontSee('View research', false);
        $response->assertDontSee($metadataResearchHref, false);
        $response->assertDontSee($storedEmailResearchHref, false);
    }

    public function test_email_thought_detail_omits_view_research_link_when_research_thought_id_does_not_resolve(): void
    {
        $owner = User::factory()->create();
        $researchThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research later deleted',
            'source' => 'web',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);
        $staleResearchId = $researchThought->id;

        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Email body with stale research id',
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Email with deleted research',
                'research_thought_id' => $staleResearchId,
            ],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $owner->id]);
        $importedEmail = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-stale-research',
            'provider_thread_id' => 'thread-stale-research',
            'direction' => 'received',
            'subject' => 'Imported subject',
            'from_json' => [['email' => 'sender@example.com', 'name' => 'Sender']],
            'to_json' => [['email' => 'owner@example.com', 'name' => 'Owner']],
            'participants_json' => [['role' => 'from', 'email' => 'sender@example.com', 'name' => 'Sender']],
            'sent_at' => now()->subMinute(),
            'received_at' => now(),
            'body_text' => 'Body',
            'processing_status' => 'imported',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $staleResearchId,
        ]);

        $emailThought->update([
            'source_metadata' => array_merge($emailThought->source_metadata ?? [], [
                'imported_email_id' => $importedEmail->id,
            ]),
        ]);

        $researchThought->delete();
        $this->assertDatabaseMissing('thoughts', ['id' => $staleResearchId]);
        $this->assertNull($importedEmail->fresh()->research_thought_id);

        $orphanResearchHref = route('idea.research.show', $staleResearchId);
        $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

        $response->assertOk();
        $response->assertDontSee('View research', false);
        $response->assertDontSee($orphanResearchHref, false);
    }

    public function test_email_thought_detail_page_falls_back_when_imported_email_is_missing(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Fallback email body from thought',
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => 999999,
                'subject' => 'Fallback metadata subject',
                'from' => [
                    ['email' => 'fallback@example.com', 'name' => 'Fallback Sender'],
                ],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertSee('Fallback email body from thought');
        $response->assertSee('Fallback metadata subject');
        $response->assertSee('fallback@example.com');
    }

    public function test_email_thought_detail_sender_rule_shows_whitelist_sender_for_imported_email_without_rule(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $thought = $this->createImportedEmailThought($user, [
            'from_json' => [['email' => 'sender@example.com', 'name' => 'Sender']],
        ]);

        $this->actingAs($user)
            ->get(route('thoughts.show', $thought))
            ->assertOk()
            ->assertSee('Sender rule')
            ->assertSee('sender@example.com')
            ->assertSee('Whitelist sender');
    }

    public function test_email_thought_detail_sender_rule_card_renders_for_captured_inbound_email(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $thought = $this->createCapturedInboundEmailThought($user, [
            'rule_email' => 'postmark-sender@example.com',
        ]);

        $this->actingAs($user)
            ->get(route('thoughts.show', $thought))
            ->assertOk()
            ->assertSee('Sender rule')
            ->assertSee('postmark-sender@example.com');
    }

    public function test_email_thought_detail_sender_rule_shows_current_rule_state_when_rule_exists(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $thought = $this->createImportedEmailThought($user, [
            'from_json' => [['email' => 'existing@example.com', 'name' => 'Existing Sender']],
        ]);
        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'existing@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $this->actingAs($user)
            ->get(route('thoughts.show', $thought))
            ->assertOk()
            ->assertSee('Sender rule')
            ->assertSee('Current rule')
            ->assertSee('Ignore');
    }

    public function test_email_thought_detail_sender_rule_keeps_whitelist_sender_quick_action_for_ignore_and_review_rules(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        foreach ([EmailSenderRule::ACTION_IGNORE, EmailSenderRule::ACTION_REVIEW] as $action) {
            EmailSenderRule::query()->delete();

            $user = User::factory()->create();
            $thought = $this->createImportedEmailThought($user, [
                'from_json' => [['email' => $action.'@example.com', 'name' => ucfirst($action).' Sender']],
            ]);
            EmailSenderRule::query()->create([
                'user_id' => $user->id,
                'sender_email' => $action.'@example.com',
                'action' => $action,
            ]);

            $response = $this->actingAs($user)
                ->get(route('thoughts.show', $thought))
                ->assertOk();

            $this->assertSenderRuleCardContains($response, 'Whitelist sender');
            $this->assertSenderRuleCardContains($response, ucfirst($action));
        }
    }

    public function test_email_thought_detail_sender_rule_existing_allow_rule_shows_remove_from_whitelist(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $thought = $this->createImportedEmailThought($user, [
            'from_json' => [['email' => 'allow@example.com', 'name' => 'Allow Sender']],
        ]);
        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'allow@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $response = $this->actingAs($user)
            ->get(route('thoughts.show', $thought))
            ->assertOk();

        $this->assertSenderRuleCardContains($response, 'Remove from whitelist');
        $this->assertSenderRuleCardDoesNotContain($response, 'Whitelist sender');
    }

    public function test_email_thought_detail_sender_rule_imported_email_falls_back_to_plain_string_source_metadata_from_when_stored_sender_is_unusable(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Imported email fallback body',
            'source' => 'email',
            'source_metadata' => [
                'from' => 'Metadata Sender <metadata-fallback@example.com>',
            ],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $importedEmail = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'imported-string-fallback-'.uniqid(),
            'direction' => 'received',
            'subject' => 'Imported subject',
            'body_text' => 'Imported body text',
            'from_json' => [['name' => 'Name Only Sender']],
            'processing_status' => 'imported',
            'thought_id' => $thought->id,
        ]);

        $thought->update([
            'source_metadata' => array_merge($thought->source_metadata ?? [], [
                'imported_email_id' => $importedEmail->id,
            ]),
        ]);

        $response = $this->actingAs($user)
            ->get(route('thoughts.show', $thought))
            ->assertOk();

        $this->assertSenderRuleCardContains($response, 'metadata-fallback@example.com');
        $this->assertSenderRuleCardDoesNotContain($response, 'Sender rule unavailable for this email.');
    }

    public function test_email_thought_detail_sender_rule_imported_email_does_not_use_later_from_json_entries_when_first_entry_is_unusable(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Imported email later entry body',
            'source' => 'email',
            'source_metadata' => [
                'from' => 'Metadata Sender <metadata-first-valid@example.com>',
            ],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $importedEmail = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'imported-later-entry-'.uniqid(),
            'direction' => 'received',
            'subject' => 'Imported subject',
            'body_text' => 'Imported body text',
            'from_json' => [
                ['name' => 'Broken First Entry'],
                ['email' => 'later@example.com', 'name' => 'Later Sender'],
            ],
            'processing_status' => 'imported',
            'thought_id' => $thought->id,
        ]);

        $thought->update([
            'source_metadata' => array_merge($thought->source_metadata ?? [], [
                'imported_email_id' => $importedEmail->id,
            ]),
        ]);

        $response = $this->actingAs($user)
            ->get(route('thoughts.show', $thought))
            ->assertOk();

        $this->assertSenderRuleCardContains($response, 'metadata-first-valid@example.com');
        $this->assertSenderRuleCardDoesNotContain($response, 'later@example.com');
    }

    public function test_email_thought_detail_sender_rule_captured_inbound_email_falls_back_to_plain_string_source_metadata_from_when_stored_sender_is_unusable(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Captured inbound metadata fallback body',
            'source' => 'email',
            'source_metadata' => [
                'from' => 'Metadata Sender <captured-fallback@example.com>',
            ],
        ]);

        $captured = CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'captured-string-fallback-'.uniqid(),
            'sender_email' => '',
            'subject' => 'Captured subject',
            'body_text' => 'Captured body text',
            'received_at' => now(),
            'rule_action' => 'review',
            'thought_id' => $thought->id,
            'processing_status' => 'imported',
        ]);

        $thought->update([
            'source_metadata' => array_merge($thought->source_metadata ?? [], [
                'captured_inbound_email_id' => $captured->id,
            ]),
        ]);

        $response = $this->actingAs($user)
            ->get(route('thoughts.show', $thought))
            ->assertOk();

        $this->assertSenderRuleCardContains($response, 'captured-fallback@example.com');
        $this->assertSenderRuleCardDoesNotContain($response, 'Sender rule unavailable for this email.');
    }

    public function test_email_thought_detail_sender_rule_feature_flag_disabled_hides_card(): void
    {
        config(['services.email_sender_policy.enabled' => false]);

        $user = User::factory()->create();
        $thought = $this->createImportedEmailThought($user, [
            'from_json' => [['email' => 'hidden@example.com', 'name' => 'Hidden Sender']],
        ]);

        $this->actingAs($user)
            ->get(route('thoughts.show', $thought))
            ->assertOk()
            ->assertDontSee('Sender rule')
            ->assertDontSee('Whitelist sender');
    }

    public function test_email_thought_detail_sender_rule_unresolved_sender_shows_unavailable_message(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Email without sender',
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Missing sender metadata',
                'from' => [['name' => 'Missing Email']],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('thoughts.show', $thought))
            ->assertOk()
            ->assertSee('Sender rule')
            ->assertSee('Sender rule unavailable for this email.')
            ->assertDontSee('Whitelist sender');
    }

    public function test_imported_email_lookup_falls_back_to_thought_id_when_metadata_id_is_stale(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => 999999,
            ],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $owner->id]);
        $importedEmail = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-stale-id',
            'direction' => 'received',
            'processing_status' => 'imported',
            'thought_id' => $thought->id,
        ]);

        $this->assertSame($importedEmail->id, $thought->importedEmail()?->id);
    }

    public function test_thought_detail_page_renders_an_inline_reply_form(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Parent detail thought',
        ]);

        $response = $this->actingAs($user)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertSee('name="parent_id"', false);
        $response->assertSee('value="'.$thought->id.'"', false);
        $response->assertSee(route('thoughts.store'), false);
    }

    public function test_email_thought_detail_header_includes_emails_stream_link_and_destination_ok(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Detail body email type',
            'source' => 'email',
        ]);

        $href = route('idea.stream.emails');
        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertThoughtBadgeLink($response, 'Email', $href);

        $this->actingAs($owner)->get($href)->assertOk();
    }

    public function test_jira_thought_detail_header_includes_jira_stream_link_and_destination_ok(): void
    {
        config(['services.jira.enabled' => true]);

        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Detail body jira type',
            'source' => 'jira',
        ]);

        $href = route('idea.stream.jira');
        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertThoughtBadgeLink($response, 'Jira', $href);

        $this->actingAs($owner)->get($href)->assertOk();
    }

    public function test_research_thought_detail_header_includes_research_stream_link_and_destination_ok(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Detail body research type',
            'source' => 'web',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $href = route('idea.stream.research');
        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertThoughtBadgeLink($response, 'Research', $href);

        $this->actingAs($owner)->get($href)->assertOk();
    }

    public function test_plan_thought_detail_header_includes_plans_stream_link_and_destination_ok(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Detail body plan type',
            'source' => 'web',
            'metadata' => ['type' => 'plan', 'tags' => []],
        ]);

        $href = route('idea.stream.plans');
        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertThoughtBadgeLink($response, 'Plan', $href);

        $this->actingAs($owner)->get($href)->assertOk();
    }

    public function test_meeting_thought_detail_header_includes_meetings_stream_link_and_destination_ok(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Detail body meeting type',
            'source' => 'meeting',
            'metadata' => ['type' => 'meeting', 'tags' => []],
        ]);

        $href = route('idea.stream.meetings');
        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertThoughtBadgeLink($response, 'Meeting', $href);

        $this->actingAs($owner)->get($href)->assertOk();
    }

    public function test_non_canonical_source_detail_header_renders_human_readable_non_link_label(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Detail body web type',
            'source' => 'web',
            'metadata' => null,
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertThoughtBadgeSpan($response, 'Web');
        $this->assertNoThoughtBadgeLink($response, 'Web');
    }

    public function test_missing_source_detail_header_falls_back_to_thought_label(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Detail body without source',
            'source' => null,
            'metadata' => null,
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertThoughtBadgeSpan($response, 'Thought');
        $this->assertNoThoughtBadgeLink($response, 'Thought');
    }

    public function test_video_thought_detail_exposes_video_research_preview_on_presenter_when_linked(): void
    {
        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=vidPreviewPayload01';

        $research = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'research',
            'content' => '# Video linked research preview body unique QP99',
            'metadata' => ['type' => 'research', 'tags' => ['research', 'video', 'preview-tag-qp99']],
        ]);

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'vidPreviewPayload01',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_MANUAL,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_PASTED,
                'research_thought_id' => $research->id,
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $detail = $response->viewData('thoughtDetail');
        $this->assertInstanceOf(ThoughtDetailPresenter::class, $detail);
        $preview = $detail->videoResearchPreview();
        $this->assertIsArray($preview);
        $this->assertArrayHasKey('full_research_url', $preview);
        $this->assertArrayHasKey('root_html', $preview);
        $this->assertArrayHasKey('section_html_chunks', $preview);
        $this->assertArrayHasKey('tags', $preview);
        $this->assertContains('preview-tag-qp99', $preview['tags'] ?? []);
        $this->assertStringContainsString(route('idea.research.show', $research), $preview['full_research_url'] ?? '');
        $this->assertStringContainsString(
            'Video linked research preview body unique QP99',
            strip_tags($preview['root_html'] ?? '')
        );

        $response->assertSee('Research tags', false);
        $response->assertSee('#preview-tag-qp99', false);
    }

    public function test_video_thought_detail_shows_transcript_status_canonical_link_and_view_research(): void
    {
        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=detailVidResearch01';

        $research = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'research',
            'content' => '# Linked from video',
            'metadata' => ['type' => 'research', 'tags' => ['video']],
        ]);

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'detailVidResearch01',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_MANUAL,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_PASTED,
                'research_thought_id' => $research->id,
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $response->assertSee('data-thought-detail-kind="video"', false);
        $xpath = $this->xpathFromResponse($response);
        $header = $xpath->query('//*[@data-thought-detail-kind="video"]')->item(0);
        $this->assertNotNull($header);
        $this->assertStringNotContainsString('Video metadata', $header->textContent ?? '');
        $sidebar = $xpath->query('//*[@data-thought-detail-sidebar="video"]')->item(0);
        $this->assertNotNull($sidebar);
        $this->assertStringContainsString('Video metadata', $sidebar->textContent ?? '');
        $response->assertSee('Video ID', false);
        $response->assertSee('detailVidResearch01', false);
        $response->assertSee('Transcript added manually', false);
        $response->assertSee($canonical, false);
        $response->assertSee('View research', false);
        $response->assertSee('Rerun research', false);
        $sidebarHtml = $sidebar->ownerDocument->saveHTML($sidebar);
        $this->assertStringContainsString(route('videos.store'), $sidebarHtml);
        $response->assertSee('name="youtube_url"', false);
        $response->assertSee('value="'.$canonical.'"', false);
        $response->assertSee('name="research_now"', false);
        $response->assertSee(route('idea.research.show', $research), false);
    }

    public function test_video_thought_detail_shows_related_email_when_metadata_links_email(): void
    {
        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=detailVidRelatedEmail01';

        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Email body for related video test',
            'metadata' => ['type' => 'email'],
        ]);

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'detailVidRelatedEmail01',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_MANUAL,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_PASTED,
                'email_thought_id' => $emailThought->id,
                'email_subject' => 'Newsletter with video link',
                'email_sender' => 'newsletter@example.com',
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $response->assertSee('Related email', false);
        $response->assertSee('Newsletter with video link', false);
        $response->assertSee('newsletter@example.com', false);
        $response->assertSee('View email', false);
        $response->assertSee(route('thoughts.show', $emailThought), false);
    }

    public function test_video_thought_detail_shows_transcript_content_in_a_dedicated_block(): void
    {
        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=detailVidTranscript06';

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'detailVidTranscript06',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'tags' => [],
            ],
        ]);

        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $video->id,
            'embedding' => null,
            'source' => 'video',
            'content' => "## Transcript\n\nUNIQUE_TRANSCRIPT_BODY_VISIBLE_123",
            'metadata' => [
                'video_section_type' => 'transcript',
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $response->assertSee('Transcript', false);
        $response->assertSee('UNIQUE_TRANSCRIPT_BODY_VISIBLE_123', false);
    }

    public function test_video_thought_detail_left_column_heading_order_content_research_preview_transcript(): void
    {
        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=vidOrderMainCol01';

        $research = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'research',
            'content' => '# Order test root ZZ1',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'vidOrderMainCol01',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'research_thought_id' => $research->id,
                'tags' => [],
            ],
        ]);

        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $video->id,
            'embedding' => null,
            'source' => 'video',
            'content' => "## Transcript\n\nUNIQUE_ORDER_TX_55",
            'metadata' => [
                'video_section_type' => 'transcript',
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $this->assertSame(
            ['Content', 'Research preview', 'Transcript'],
            $this->thoughtDetailMainColumnHeadingLabels($response)
        );
    }

    public function test_video_thought_detail_transcript_heading_follows_content_when_no_research_preview(): void
    {
        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=vidNoPreviewOrder02';

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'vidNoPreviewOrder02',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'tags' => [],
            ],
        ]);

        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $video->id,
            'embedding' => null,
            'source' => 'video',
            'content' => "## Transcript\n\nUNIQUE_NO_PREVIEW_TX_66",
            'metadata' => [
                'video_section_type' => 'transcript',
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $this->assertSame(
            ['Content', 'Transcript'],
            $this->thoughtDetailMainColumnHeadingLabels($response)
        );
        $response->assertDontSee('Research preview', false);
    }

    public function test_video_thought_detail_minimal_video_main_column_only_content_heading(): void
    {
        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=vidMinimalMain03';

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'vidMinimalMain03',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_UNAVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_NONE,
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $this->assertSame(
            ['Content'],
            $this->thoughtDetailMainColumnHeadingLabels($response)
        );
    }

    public function test_video_thought_detail_does_not_list_transcript_child_as_a_reply(): void
    {
        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=detailVidReply02';

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'detailVidReply02',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'tags' => [],
            ],
        ]);

        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $video->id,
            'embedding' => null,
            'source' => 'video',
            'content' => "## Transcript\n\nUNIQUE_TRANSCRIPT_NOT_A_REPLY_77",
            'metadata' => [
                'video_section_type' => 'transcript',
                'tags' => [],
            ],
        ]);

        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $video->id,
            'embedding' => null,
            'source' => 'web',
            'content' => 'REAL_USER_REPLY_MARKER_88',
            'metadata' => ['tags' => []],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $response->assertSee('UNIQUE_TRANSCRIPT_NOT_A_REPLY_77', false);
        $response->assertSee('REAL_USER_REPLY_MARKER_88', false);

        $xpath = $this->xpathFromResponse($response);
        $replySections = $xpath->query("//section[.//p[normalize-space(.)='Replies']]");

        $this->assertSame(1, $replySections->length);

        $replyText = trim($replySections->item(0)?->textContent ?? '');
        $this->assertStringNotContainsString('UNIQUE_TRANSCRIPT_NOT_A_REPLY_77', $replyText);
        $this->assertStringContainsString('REAL_USER_REPLY_MARKER_88', $replyText);
    }

    public function test_video_thought_detail_shows_research_now_form_when_ready_without_linked_research(): void
    {
        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=detailVidReady03';

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'detailVidReady03',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $response->assertSee('Research now', false);
        $response->assertSee(route('videos.store'), false);
        $response->assertSee('name="youtube_url"', false);
        $response->assertSee('value="'.$canonical.'"', false);
        $response->assertSee('name="research_now"', false);
    }

    public function test_video_thought_detail_shows_fetch_transcript_form_when_transcript_is_missing_and_not_pending(): void
    {
        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=detailVidFetch05';

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'detailVidFetch05',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_UNAVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_NONE,
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $response->assertSee('Fetch transcript', false);
        $response->assertSee(route('videos.store'), false);
        $response->assertSee('name="youtube_url"', false);
        $response->assertSee('value="'.$canonical.'"', false);
        $response->assertDontSee('name="research_now"', false);
        $response->assertSee('Add transcript', false);
        $response->assertSee('Save transcript', false);
        $response->assertSee('name="transcript"', false);
        $response->assertSee('name="return_thought_id"', false);
        $response->assertSee('value="'.$video->id.'"', false);
    }

    public function test_video_thought_detail_hides_add_transcript_form_when_transcript_text_exists(): void
    {
        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=detailVidHasTranscript99';

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'detailVidHasTranscript99',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'tags' => [],
            ],
        ]);

        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $video->id,
            'embedding' => null,
            'source' => 'video',
            'content' => "## Transcript\n\nSome transcript body here.",
            'metadata' => [
                'video_section_type' => 'transcript',
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $response->assertDontSee('Add transcript', false);
        $response->assertDontSee('Save transcript', false);
    }

    public function test_demo_mode_video_thought_detail_does_not_expose_raw_canonical_url(): void
    {
        config(['services.demo_mode.enabled' => true]);

        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=detailVidDemo04';

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'detailVidDemo04',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'tags' => [],
            ],
        ]);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'video-detail-demo-url',
        ])->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $response->assertDontSee($canonical, false);
        $response->assertDontSee('Open video', false);
        $response->assertDontSee('Research now', false);
        $response->assertDontSee('Rerun research', false);
        $response->assertDontSee('Add transcript', false);
    }

    public function test_demo_mode_video_thought_detail_does_not_expose_raw_transcript_text(): void
    {
        config(['services.demo_mode.enabled' => true]);

        $owner = User::factory()->create();
        $canonical = 'https://www.youtube.com/watch?v=detailVidDemoTranscript07';

        $video = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'video',
            'content' => 'YouTube: '.$canonical,
            'metadata' => [
                'type' => 'video',
                'video_id' => 'detailVidDemoTranscript07',
                'video_url' => $canonical,
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'tags' => [],
            ],
        ]);

        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $video->id,
            'embedding' => null,
            'source' => 'video',
            'content' => "## Transcript\n\nUNIQUE_TRANSCRIPT_SECRET_456",
            'metadata' => [
                'video_section_type' => 'transcript',
                'tags' => [],
            ],
        ]);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'video-detail-demo-transcript',
        ])->actingAs($owner)->get(route('thoughts.show', $video));

        $response->assertOk();
        $response->assertDontSee('UNIQUE_TRANSCRIPT_SECRET_456', false);
    }

    public function test_jira_disabled_jira_thought_detail_header_does_not_link_to_jira_stream(): void
    {
        config(['services.jira.enabled' => false]);

        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Jira detail when off',
            'source' => 'jira',
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertThoughtBadgeSpan($response, 'Jira');
        $this->assertNoThoughtBadgeLink($response, 'Jira');
    }

    public function test_user_can_post_a_reply_from_the_detail_page(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Parent detail thought',
        ]);
        $this->mock(ThoughtCaptureService::class, function ($mock) use ($thought, $user): void {
            $mock->shouldReceive('create')
                ->once()
                ->with(\Mockery::on(function (array $options) use ($thought, $user): bool {
                    return ($options['content'] ?? null) === 'Reply from detail page'
                        && ($options['parent_id'] ?? null) === $thought->id
                        && ($options['user_id'] ?? null) === $user->id
                        && ($options['source'] ?? null) === 'web';
                }))
                ->andReturnUsing(function (array $options) {
                    $reply = Thought::create([
                        'content' => $options['content'],
                        'user_id' => $options['user_id'],
                        'parent_id' => $options['parent_id'],
                        'source' => $options['source'],
                        'source_metadata' => $options['source_metadata'] ?? null,
                        'metadata' => ['tags' => []],
                        'embedding' => null,
                    ]);

                    return [
                        'thought' => $reply,
                        'chunked' => false,
                    ];
                });
        });

        $response = $this->actingAs($user)->from(route('thoughts.show', $thought))->post(route('thoughts.store'), [
            'content' => 'Reply from detail page',
            'parent_id' => $thought->id,
        ]);

        $response->assertRedirect(route('idea.index'));
        $this->assertDatabaseHas('thoughts', [
            'user_id' => $user->id,
            'parent_id' => $thought->id,
            'content' => 'Reply from detail page',
        ]);
    }

    public function test_detail_tag_row_with_existing_tags_renders_editable_tag_affordance(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Tagged thought detail body',
            'metadata' => ['tags' => ['alpha', 'beta']],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertDetailTagEditControl($response, $thought);
    }

    public function test_detail_tag_row_with_no_tags_still_renders_editable_tag_affordance(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Untagged thought detail body',
            'metadata' => null,
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertDetailTagEditControl($response, $thought);
    }

    public function test_detail_tag_row_with_cleared_tags_shape_still_renders_editable_tag_affordance(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => 'Cleared tags thought detail body',
            'metadata' => ['tags' => []],
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $this->assertDetailTagEditControl($response, $thought);
    }

    private function assertThoughtBadgeLink(TestResponse $response, string $label, string $href): void
    {
        $xpath = $this->xpathFromResponse($response);
        $links = $xpath->query(sprintf(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' thought-type-badge-link ') and normalize-space(.)='%s' and @href='%s']",
            $label,
            $href
        ));

        $this->assertSame(1, $links->length);
    }

    private function assertThoughtBadgeSpan(TestResponse $response, string $label): void
    {
        $xpath = $this->xpathFromResponse($response);
        $spans = $xpath->query(sprintf(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' thought-type-badge ') and normalize-space(.)='%s']",
            $label
        ));

        $this->assertSame(1, $spans->length);
    }

    private function assertNoThoughtBadgeLink(TestResponse $response, string $label): void
    {
        $xpath = $this->xpathFromResponse($response);
        $links = $xpath->query(sprintf(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' thought-type-badge-link ') and normalize-space(.)='%s']",
            $label
        ));

        $this->assertSame(0, $links->length);
    }

    private function assertDetailTagEditControl(TestResponse $response, Thought $thought): void
    {
        $xpath = $this->xpathFromResponse($response);
        $streamUrl = route('idea.stream');
        $updateTagsUrl = route('ideas.update-tags', $thought);

        $buttons = $xpath->query(sprintf(
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' rounded-2xl ') and .//p[normalize-space(.)='Thought detail']]//div[@data-stream-base-url=%s and contains(@x-data, %s)]//button[@type='button' and @aria-label='Edit tags' and normalize-space(.)='Edit']",
            $this->xpathLiteral($streamUrl),
            $this->xpathLiteral($updateTagsUrl)
        ));

        $this->assertSame(1, $buttons->length);
    }

    private function assertSenderRuleCardContains(TestResponse $response, string $text): void
    {
        $cardText = $this->senderRuleCardText($response);

        $this->assertStringContainsString($text, $cardText);
    }

    private function assertSenderRuleCardDoesNotContain(TestResponse $response, string $text): void
    {
        $cardText = $this->senderRuleCardText($response);

        $this->assertStringNotContainsString($text, $cardText);
    }

    /**
     * @return list<string>
     */
    private function thoughtDetailMainColumnHeadingLabels(TestResponse $response): array
    {
        $xpath = $this->xpathFromResponse($response);
        $main = $xpath->query('//*[@data-thought-detail-main]')->item(0);
        if ($main === null) {
            return [];
        }

        $labels = [];
        $nodes = $xpath->query(
            './/article//p[contains(@class, "uppercase") and contains(@class, "text-memory-violet")]',
            $main
        );
        foreach ($nodes as $node) {
            $labels[] = trim($node->textContent ?? '');
        }

        return $labels;
    }

    private function xpathFromResponse(TestResponse $response): \DOMXPath
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new \DOMXPath($dom);
    }

    private function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        if (! str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        $parts = explode("'", $value);

        return "concat('".implode("', \"'\", '", $parts)."')";
    }

    private function senderRuleCardText(TestResponse $response): string
    {
        $xpath = $this->xpathFromResponse($response);
        $cards = $xpath->query(
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' bg-white/60 ') and contains(concat(' ', normalize-space(@class), ' '), ' p-4 ') and ./p[normalize-space(.)='Sender rule']]"
        );

        $this->assertSame(1, $cards->length);

        return trim($cards->item(0)?->textContent ?? '');
    }

    private function createImportedEmailThought(User $user, array $overrides = []): Thought
    {
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Imported email thought body',
            'source' => 'email',
            'source_metadata' => [],
        ]);

        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $importedEmail = ImportedEmail::query()->create(array_merge([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'imported-'.uniqid(),
            'direction' => 'received',
            'subject' => 'Imported subject',
            'body_text' => 'Imported body text',
            'processing_status' => 'imported',
            'thought_id' => $thought->id,
        ], $overrides));

        $thought->update([
            'source_metadata' => array_merge($thought->source_metadata ?? [], [
                'imported_email_id' => $importedEmail->id,
            ]),
        ]);

        return $thought->fresh();
    }

    private function createCapturedInboundEmailThought(User $user, array $overrides = []): Thought
    {
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Captured inbound email thought body',
            'source' => 'email',
            'source_metadata' => [],
        ]);

        $captured = CapturedInboundEmail::query()->create(array_merge([
            'user_id' => $user->id,
            'message_id' => 'captured-'.uniqid(),
            'sender_email' => 'captured@example.com',
            'subject' => 'Captured subject',
            'body_text' => 'Captured body',
            'received_at' => now(),
            'rule_action' => 'review',
            'thought_id' => $thought->id,
            'processing_status' => 'imported',
        ], $overrides));

        $thought->update([
            'source_metadata' => array_merge($thought->source_metadata ?? [], [
                'captured_inbound_email_id' => $captured->id,
            ]),
        ]);

        return $thought->fresh();
    }

    /**
     * @return array{0: User, 1: Thought, 2: Thought}
     */
    private function createEmailThoughtWithLinkedResearchPreviewFixture(): array
    {
        return $this->createEmailThoughtWithLinkedResearchContent(
            self::EMAIL_RESEARCH_PREVIEW_INTRO,
            [
                "## First\n\n".self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE,
                "## Second\n\n".self::EMAIL_RESEARCH_PREVIEW_SECTION_TWO,
                '## Third\n\n'.self::EMAIL_RESEARCH_PREVIEW_SECTION_THREE,
            ],
        );
    }

    /**
     * @param  array<int, string>  $sectionMarkdownBodies
     * @return array{0: User, 1: Thought, 2: Thought}
     */
    private function createEmailThoughtWithLinkedResearchContent(string $researchRootMarkdown, array $sectionMarkdownBodies = []): array
    {
        $owner = User::factory()->create();
        $researchThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => $researchRootMarkdown,
            'source' => 'web',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        foreach ($sectionMarkdownBodies as $sectionBody) {
            Thought::factory()->create([
                'user_id' => $owner->id,
                'parent_id' => $researchThought->id,
                'embedding' => null,
                'content' => $sectionBody,
                'source' => 'web',
                'metadata' => ['tags' => []],
            ]);
        }

        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Email body for research preview',
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Email with research',
                'research_thought_id' => $researchThought->id,
            ],
        ]);

        return [$owner, $emailThought->fresh(), $researchThought->fresh()];
    }

    private function attachImportedEmailWithResearchThoughtId(User $owner, Thought $emailThought, string $researchThoughtId): void
    {
        $account = MailAccount::factory()->create(['user_id' => $owner->id]);
        $importedEmail = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-missing-research-'.uniqid(),
            'provider_thread_id' => 'thread-missing-research',
            'direction' => 'received',
            'subject' => 'Imported subject',
            'from_json' => [['email' => 'sender@example.com', 'name' => 'Sender']],
            'to_json' => [['email' => 'owner@example.com', 'name' => 'Owner']],
            'participants_json' => [['role' => 'from', 'email' => 'sender@example.com', 'name' => 'Sender']],
            'sent_at' => now()->subMinute(),
            'received_at' => now(),
            'body_text' => 'Body',
            'processing_status' => 'imported',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $researchThoughtId,
        ]);

        $emailThought->update([
            'source_metadata' => array_merge($emailThought->source_metadata ?? [], [
                'imported_email_id' => $importedEmail->id,
            ]),
        ]);
    }

    private function assertEmailDetailOmitsResearchPreviewAndResearchCtas(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertEmailDetailOmitsResearchPreviewViewModel($response);
        $response->assertViewHas('thoughtDetail', fn (ThoughtDetailPresenter $d) => $d->linkedResearchUrl() === null);
        $response->assertDontSee('View research', false);
    }

    private function assertEmailDetailOmitsResearchPreviewPanelAndFullResearchLink(TestResponse $response): void
    {
        $this->assertEmailDetailOmitsResearchPreviewViewModel($response);
    }

    private function assertEmailDetailOmitsResearchPreviewViewModel(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertViewHas('thoughtDetail', fn (ThoughtDetailPresenter $d) => $d->emailResearchPreview() === null);
        $response->assertDontSee('View full research', false);
        $response->assertDontSee('Research preview', false);
    }

    /**
     * @param  array{
     *     expect_intro_in_root_html: bool,
     *     expect_section_plain_text: array<int, string>,
     *     expect_absent_plain_text: array<int, string>
     * }  $expectations
     */
    private function assertEmailResearchPreviewViewModel(TestResponse $response, Thought $researchThought, array $expectations): void
    {
        $response->assertViewHas('thoughtDetail', function ($detail) use ($researchThought, $expectations) {
            $this->assertInstanceOf(ThoughtDetailPresenter::class, $detail);
            $preview = $detail->emailResearchPreview();
            $this->assertIsArray($preview);
            $this->assertSame(route('idea.research.show', $researchThought), $preview['full_research_url']);
            $this->assertArrayHasKey('root_html', $preview);
            $this->assertArrayHasKey('section_html_chunks', $preview);
            $this->assertIsArray($preview['section_html_chunks']);
            $this->assertLessThanOrEqual(2, count($preview['section_html_chunks']));

            if ($expectations['expect_intro_in_root_html']) {
                $this->assertStringContainsString(self::EMAIL_RESEARCH_PREVIEW_INTRO, $preview['root_html']);
            } else {
                $this->assertStringNotContainsString(self::EMAIL_RESEARCH_PREVIEW_INTRO, $preview['root_html']);
            }

            $combined = $preview['root_html'].implode('', $preview['section_html_chunks']);
            foreach ($expectations['expect_section_plain_text'] as $plain) {
                $this->assertStringContainsString($plain, $combined);
            }
            foreach ($expectations['expect_absent_plain_text'] as $plain) {
                $this->assertStringNotContainsString($plain, $combined);
            }

            return true;
        });
    }

    /**
     * @return array{0: User, 1: Thought, 2: Thought}
     */
    private function createEmailThoughtWithLinkedImportedResearchPreviewFixture(): array
    {
        [$owner, $emailThought, $researchThought] = $this->createEmailThoughtWithLinkedResearchPreviewFixture();

        $account = MailAccount::factory()->create(['user_id' => $owner->id]);
        $importedEmail = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-research-link',
            'provider_thread_id' => 'thread-research-link',
            'direction' => 'received',
            'subject' => 'Imported subject',
            'from_json' => [['email' => 'sender@example.com', 'name' => 'Sender']],
            'to_json' => [['email' => 'owner@example.com', 'name' => 'Owner']],
            'participants_json' => [['role' => 'from', 'email' => 'sender@example.com', 'name' => 'Sender']],
            'sent_at' => now()->subMinute(),
            'received_at' => now(),
            'body_text' => 'Body',
            'processing_status' => 'imported',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $researchThought->id,
        ]);

        $emailThought->update([
            'source_metadata' => array_merge($emailThought->source_metadata ?? [], [
                'imported_email_id' => $importedEmail->id,
            ]),
        ]);

        return [$owner, $emailThought->fresh(), $researchThought->fresh()];
    }

    private function assertEmailDetailResearchPreviewContract(TestResponse $response, Thought $researchThought): void
    {
        $response->assertOk();
        $this->assertEmailResearchPreviewViewModel($response, $researchThought, [
            'expect_intro_in_root_html' => true,
            'expect_section_plain_text' => [
                self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE,
                self::EMAIL_RESEARCH_PREVIEW_SECTION_TWO,
            ],
            'expect_absent_plain_text' => [self::EMAIL_RESEARCH_PREVIEW_SECTION_THREE],
        ]);
        $response->assertViewHas('thoughtDetail', fn (ThoughtDetailPresenter $d) => $d->linkedResearchUrl() === route('idea.research.show', $researchThought));
        $response->assertSee('Research preview', false);
        $response->assertSee('View full research', false);
        $response->assertSee(self::EMAIL_RESEARCH_PREVIEW_INTRO, false);
        $response->assertSee(self::EMAIL_RESEARCH_PREVIEW_SECTION_ONE, false);
        $response->assertSee(self::EMAIL_RESEARCH_PREVIEW_SECTION_TWO, false);
        $response->assertDontSee(self::EMAIL_RESEARCH_PREVIEW_SECTION_THREE, false);
    }
}
