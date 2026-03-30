<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InboxPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_inbox_requires_authentication(): void
    {
        $response = $this->get(route('inbox.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_inbox_shows_empty_state_when_user_has_no_items(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertSee('No inbox items right now.');
    }

    public function test_inbox_shows_only_actionable_items_for_current_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'Visible item',
            'dedupe_key' => 'visible-item',
            'status' => 'pending',
            'snoozed_until' => null,
        ]);

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'Future snoozed item',
            'dedupe_key' => 'future-snoozed-item',
            'status' => 'pending',
            'snoozed_until' => now()->addDay(),
        ]);

        InboxItem::factory()->create([
            'user_id' => $other->id,
            'title' => 'Other users item',
            'dedupe_key' => 'other-users-item',
        ]);

        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertSee('Inbox');
        $response->assertSee('Visible item');
        $response->assertSee('data-inbox-item-id=', false);
        $response->assertSee('data-inbox-initial-count="1"', false);
        $response->assertDontSee('Action buttons are added in Chunk 3.');
        $response->assertDontSee('Future snoozed item');
        $response->assertDontSee('Other users item');
    }

    public function test_layout_shows_inbox_nav_link_and_badge_for_actionable_items(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'One actionable item',
            'dedupe_key' => 'one-actionable-item',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertOk();
        $response->assertSee(route('inbox.index'), false);
        $response->assertSee('data-testid="avatar-inbox-badge"', false);
        $xpath = $this->xpathFromResponse($response);
        $badge = $xpath->query('//*[@data-testid="account-menu-inbox-badge"]')->item(0);

        $this->assertNotNull($badge);
        $this->assertSame('1', trim($badge->textContent ?? ''));
    }

    public function test_layout_caps_account_menu_inbox_badge_at_99_plus(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->count(100)->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'snoozed_until' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertOk();
        $xpath = $this->xpathFromResponse($response);
        $badge = $xpath->query('//*[@data-testid="account-menu-inbox-badge"]')->item(0);

        $this->assertNotNull($badge);
        $this->assertSame('99+', trim($badge->textContent ?? ''));
    }

    public function test_inbox_renders_item_body_as_markdown(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'Markdown item',
            'dedupe_key' => 'markdown-item',
            'body' => "## Weekly Revisit\n\n**Important**\n\n- First item\n- Second item",
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertSee('<h2>Weekly Revisit</h2>', false);
        $response->assertSee('<strong>Important</strong>', false);
        $response->assertSee('<li>First item</li>', false);
        $response->assertSee('<li>Second item</li>', false);
    }

    public function test_inbox_shows_manage_sender_rules_link_when_sender_policy_enabled(): void
    {
        Config::set('services.email_sender_policy.enabled', true);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertSee(route('settings.email-sender-rules.index'), false);
        $response->assertSee('Manage sender rules', false);
    }

    public function test_inbox_does_not_show_manage_sender_rules_link_when_sender_policy_disabled(): void
    {
        Config::set('services.email_sender_policy.enabled', false);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertDontSee('Manage sender rules', false);
    }

    private function xpathFromResponse(TestResponse $response): \DOMXPath
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new \DOMXPath($dom);
    }
}
