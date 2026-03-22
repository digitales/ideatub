<?php

namespace Tests\Feature;

use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use App\Services\ThoughtCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ThoughtShowPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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
        $xpath = $this->xpathFromResponse($response);
        $badgeSpans = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' thought-type-badge ') and normalize-space(.)='Jira']"
        );
        $this->assertSame(1, $badgeSpans->length);

        $badgeLinks = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' thought-type-badge-link ') and normalize-space(.)='Jira']"
        );
        $this->assertSame(0, $badgeLinks->length);
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

    private function xpathFromResponse(TestResponse $response): \DOMXPath
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new \DOMXPath($dom);
    }
}
