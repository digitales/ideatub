<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response->assertDontSee('Action buttons are added in Chunk 3.');
        $response->assertDontSee('Future snoozed item');
        $response->assertDontSee('Other users item');
    }

    public function test_layout_shows_account_menu_inbox_and_avatar_badge_for_actionable_items(): void
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
        $response->assertSee('data-testid="account-menu-inbox-link"', false);
        $response->assertSee('data-testid="avatar-inbox-badge"', false);
        $response->assertSee('Inbox has 1 actionable item', false);
    }

    public function test_inbox_page_layout_uses_focused_primary_navigation(): void
    {
        config(['services.jira.enabled' => true]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $xpath = $this->xpathFromResponse($response);
        $primary = $xpath->query('//*[@data-testid="primary-nav"]')->item(0);

        $this->assertNotNull($primary);

        $text = $primary->textContent ?? '';
        $this->assertStringContainsString('Ideas', $text);
        $this->assertStringContainsString('Stream', $text);
        $this->assertStringContainsString('Types', $text);
        $this->assertStringContainsString('Help', $text);
        $this->assertStringContainsString('Keyboard shortcuts', $text);
        $this->assertStringNotContainsString('Inbox', $text);
        $this->assertStringNotContainsString('Ideas to revisit', $text);

        $jiraOutsideTypes = $xpath->query(
            './/a[normalize-space(.)="Jira" and not(ancestor::*[@data-testid="types-menu-list"])]',
            $primary
        );
        $this->assertSame(0, $jiraOutsideTypes->length);
    }

    public function test_layout_shows_no_avatar_badge_when_inbox_is_clear(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertOk();
        $response->assertDontSee('data-testid="avatar-inbox-badge"', false);
    }

    public function test_avatar_badge_shows_99_plus_when_actionable_count_exceeds_99(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 100; $i++) {
            InboxItem::factory()->create([
                'user_id' => $user->id,
                'title' => 'Item '.$i,
                'dedupe_key' => 'bulk-item-'.$i,
                'status' => 'pending',
            ]);
        }

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertOk();
        $response->assertSee('data-testid="avatar-inbox-badge"', false);
        $response->assertSee('99+', false);
        $response->assertSee('Inbox has more than 99 actionable items', false);
    }

    public function test_inbox_page_mobile_nav_contains_reachable_entries_and_type_links(): void
    {
        config(['services.jira.enabled' => true]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $xpath = $this->xpathFromResponse($response);
        $panel = $xpath->query('//*[@data-testid="mobile-nav-panel"]')->item(0);

        $this->assertNotNull($panel);
        $this->assertSame(1, $xpath->query('//*[@data-testid="mobile-nav-trigger"]')->length);

        $labels = [];
        foreach ($xpath->query('.//a | .//button', $panel) as $item) {
            $labels[] = trim($item->textContent);
        }

        $this->assertContains('Ideas', $labels);
        $this->assertContains('Stream', $labels);
        $this->assertContains('Help', $labels);
        $this->assertContains('Keyboard shortcuts', $labels);
        $this->assertContains('Jira', $labels);
        $this->assertContains('Emails', $labels);
        $this->assertContains('Research', $labels);
        $this->assertContains('Plans', $labels);

        $this->assertSame(
            ['Jira', 'Emails', 'Research', 'Plans'],
            $this->mobileTypeLabelsFromResponse($response)
        );
    }

    public function test_inbox_page_mobile_nav_omits_jira_when_disabled(): void
    {
        config(['services.jira.enabled' => false]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $this->assertSame(
            ['Emails', 'Research', 'Plans'],
            $this->mobileTypeLabelsFromResponse($response)
        );
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

    private function xpathFromResponse(TestResponse $response): \DOMXPath
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new \DOMXPath($dom);
    }

    private function mobileTypeLabelsFromResponse(TestResponse $response): array
    {
        $xpath = $this->xpathFromResponse($response);
        $panel = $xpath->query('//*[@data-testid="mobile-nav-panel"]')->item(0);
        $this->assertNotNull($panel);

        $labels = [];
        foreach ($xpath->query('.//a[contains(@href, "/stream/")]', $panel) as $link) {
            $labels[] = trim($link->textContent);
        }

        return $labels;
    }
}
