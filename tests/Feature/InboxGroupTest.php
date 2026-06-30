<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_two_or_more_items_of_same_type_render_as_group(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->count(3)->create([
            'user_id' => $user->id,
            'generator_type' => 'wm_fallback',
            'title' => 'Working memory needs consolidate',
            'status' => 'pending',
            'snoozed_until' => null,
        ]);

        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertSee('data-inbox-group="wm_fallback"', false);
        $response->assertSee('3 scopes in fallback authoring', false);
        $response->assertSee('Done all', false);
        $response->assertSee('data-inbox-initial-count="3"', false);
    }

    public function test_single_item_of_a_type_renders_as_individual_card(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'generator_type' => 'wm_fallback',
            'title' => 'Working memory needs consolidate',
            'dedupe_key' => 'wm-fallback-single',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertDontSee('data-inbox-group="wm_fallback"', false);
        $response->assertSee('data-inbox-item-id=', false);
    }

    public function test_grouped_types_are_pinned_and_singles_paginate_separately(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->count(2)->create([
            'user_id' => $user->id,
            'generator_type' => 'wm_fallback',
            'title' => 'Working memory needs consolidate',
            'status' => 'pending',
            'snoozed_until' => null,
        ]);

        InboxItem::factory()->count(21)->create([
            'user_id' => $user->id,
            'generator_type' => 'weekly_revisit',
            'title' => 'Weekly revisit',
            'status' => 'pending',
            'snoozed_until' => null,
        ]);

        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertSee('data-inbox-group="wm_fallback"', false);
        $response->assertSee('data-inbox-initial-count="23"', false);
    }

    public function test_bulk_done_all_marks_every_pending_item_of_type_done(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->count(4)->create([
            'user_id' => $user->id,
            'generator_type' => 'wm_fallback',
            'status' => 'pending',
            'snoozed_until' => null,
        ]);

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'generator_type' => 'weekly_revisit',
            'dedupe_key' => 'weekly-other',
            'status' => 'pending',
            'snoozed_until' => null,
        ]);

        $response = $this->actingAs($user)->postJson(route('inbox.groups.bulk', ['generatorType' => 'wm_fallback']), [
            'action' => 'done_all',
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'cleared_count' => 4,
            'remaining_count' => 1,
        ]);

        $this->assertSame(0, InboxItem::query()
            ->where('user_id', $user->id)
            ->where('generator_type', 'wm_fallback')
            ->where('status', 'pending')
            ->count());
    }

    public function test_bulk_action_rejects_disallowed_action_for_generator_type(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->count(2)->create([
            'user_id' => $user->id,
            'generator_type' => 'wm_fallback',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->postJson(route('inbox.groups.bulk', ['generatorType' => 'wm_fallback']), [
            'action' => 'allow_all',
        ]);

        $response->assertUnprocessable();
    }

    public function test_email_sender_review_group_shows_allow_and_ignore_all_actions(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->count(2)->create([
            'user_id' => $user->id,
            'generator_type' => 'email_sender_review',
            'title' => 'Review sender',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertSee('data-inbox-group="email_sender_review"', false);
        $response->assertSee('Allow all senders', false);
        $response->assertSee('Ignore all senders', false);
    }
}
