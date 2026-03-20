<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxPageTest extends TestCase
{
    use RefreshDatabase;

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
        $response->assertSee('data-testid="inbox-badge"', false);
    }
}
