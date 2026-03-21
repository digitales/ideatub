<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
