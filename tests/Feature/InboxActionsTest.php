<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\InboxItemAction;
use App\Models\Thought;
use App\Models\User;
use App\Services\ThoughtCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InboxActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_mark_own_inbox_item_done(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'done-item',
            'snoozed_until' => now()->addDay(),
        ]);

        $response = $this->actingAs($user)->post(route('inbox.done', $item));

        $response->assertRedirect(route('inbox.index'));
        $item->refresh();

        $this->assertNotNull($item->actioned_at);
        $this->assertNull($item->snoozed_until);
        $this->assertDatabaseHas('inbox_items', [
            'id' => $item->id,
            'status' => 'done',
        ]);
        $this->assertDatabaseHas('inbox_item_actions', [
            'inbox_item_id' => $item->id,
            'action_type' => 'done',
        ]);
    }

    public function test_user_can_snooze_own_inbox_item_until_tomorrow(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'snooze-item',
        ]);

        $response = $this->actingAs($user)->post(route('inbox.snooze', $item), [
            'preset' => 'tomorrow',
        ]);

        $response->assertRedirect(route('inbox.index'));
        $item->refresh();

        $this->assertSame('pending', $item->status);
        $this->assertNotNull($item->snoozed_until);
        $this->assertDatabaseHas('inbox_item_actions', [
            'inbox_item_id' => $item->id,
            'action_type' => 'snooze',
        ]);
    }

    public function test_user_can_snooze_own_inbox_item_until_next_week(): void
    {
        Carbon::setTestNow('2026-03-20 15:30:00');
        try {
            $user = User::factory()->create();
            $item = InboxItem::factory()->create([
                'user_id' => $user->id,
                'dedupe_key' => 'snooze-next-week-item',
            ]);

            $response = $this->actingAs($user)->post(route('inbox.snooze', $item), [
                'preset' => 'next_week',
            ]);

            $response->assertRedirect(route('inbox.index'));
            $item->refresh();

            $this->assertSame('pending', $item->status);
            $this->assertNull($item->actioned_at);
            $this->assertNotNull($item->snoozed_until);
            $this->assertTrue($item->snoozed_until->equalTo(Carbon::parse('2026-03-27 00:00:00', 'UTC')));
            $this->assertDatabaseHas('inbox_item_actions', [
                'inbox_item_id' => $item->id,
                'action_type' => 'snooze',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_invalid_snooze_preset_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'invalid-snooze-item',
        ]);

        $response = $this->from(route('inbox.index'))
            ->actingAs($user)
            ->post(route('inbox.snooze', $item), ['preset' => 'later']);

        $response->assertRedirect(route('inbox.index'));
        $response->assertSessionHasErrors('preset');
        $this->assertDatabaseMissing('inbox_item_actions', [
            'inbox_item_id' => $item->id,
            'action_type' => 'snooze',
        ]);
    }

    public function test_guest_cannot_post_inbox_actions(): void
    {
        $item = InboxItem::factory()->create([
            'dedupe_key' => 'guest-guarded-item',
        ]);

        $this->post(route('inbox.done', $item))->assertRedirect(route('login'));
        $this->post(route('inbox.snooze', $item), ['preset' => 'tomorrow'])->assertRedirect(route('login'));
        $this->post(route('inbox.save-thought', $item))->assertRedirect(route('login'));
    }

    public function test_user_cannot_mutate_another_users_inbox_item(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $owner->id,
            'dedupe_key' => 'forbidden-item',
        ]);

        $this->actingAs($other)->post(route('inbox.done', $item))->assertForbidden();
        $this->actingAs($other)->post(route('inbox.snooze', $item), ['preset' => 'tomorrow'])->assertForbidden();
        $this->actingAs($other)->post(route('inbox.save-thought', $item))->assertForbidden();
    }

    public function test_json_mark_done_success(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'json-done-item',
            'snoozed_until' => now()->addDay(),
        ]);

        $response = $this->actingAs($user)->postJson(route('inbox.done', $item));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => 'Inbox item marked done.',
                'item_id' => $item->id,
                'remaining_count' => 0,
            ]);
        $item->refresh();
        $this->assertSame('done', $item->status);
    }

    public function test_json_snooze_success(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'json-snooze-item',
        ]);

        $response = $this->actingAs($user)->postJson(route('inbox.snooze', $item), [
            'preset' => 'tomorrow',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => 'Inbox item snoozed.',
                'item_id' => $item->id,
                'remaining_count' => 0,
            ]);
        $item->refresh();
        $this->assertNotNull($item->snoozed_until);
    }

    public function test_json_mark_done_remaining_count_when_another_actionable_item_remains(): void
    {
        $user = User::factory()->create();
        $first = InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'json-done-first',
        ]);
        InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'json-done-second',
        ]);

        $response = $this->actingAs($user)->postJson(route('inbox.done', $first));

        $response->assertOk()
            ->assertJson([
                'remaining_count' => 1,
                'item_id' => $first->id,
            ]);
    }

    public function test_json_invalid_snooze_preset_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'json-invalid-snooze-item',
        ]);

        $response = $this->actingAs($user)->postJson(route('inbox.snooze', $item), [
            'preset' => 'later',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['preset']);
        $this->assertDatabaseMissing('inbox_item_actions', [
            'inbox_item_id' => $item->id,
            'action_type' => 'snooze',
        ]);
    }

    public function test_json_save_as_thought_success(): void
    {
        $this->fakeOpenRouterForThoughtCapture();

        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'JSON save as thought',
            'body' => 'Body.',
            'dedupe_key' => 'json-save-thought-item',
            'generator_type' => 'weekly_revisit',
        ]);

        $response = $this->actingAs($user)->postJson(route('inbox.save-thought', $item));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => 'Saved as thought.',
                'item_id' => $item->id,
                'remaining_count' => 0,
            ]);
        $this->assertDatabaseHas('inbox_items', [
            'id' => $item->id,
            'status' => 'done',
        ]);
    }

    public function test_json_save_as_thought_failure_returns_error(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'JSON save failure',
            'body' => 'Body.',
            'dedupe_key' => 'json-save-failure-item',
            'generator_type' => 'weekly_revisit',
        ]);

        $this->mock(ThoughtCaptureService::class, function ($mock): void {
            $mock->shouldReceive('create')->andThrow(new \RuntimeException('capture failed'));
        });

        $response = $this->actingAs($user)->postJson(route('inbox.save-thought', $item));

        $response->assertStatus(503)
            ->assertJson([
                'message' => 'Unable to save inbox item as a thought.',
            ]);
        $this->assertDatabaseHas('inbox_items', [
            'id' => $item->id,
            'status' => 'pending',
        ]);
    }

    public function test_json_user_cannot_mutate_another_users_inbox_item(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $owner->id,
            'dedupe_key' => 'json-forbidden-item',
        ]);

        $this->actingAs($other)->postJson(route('inbox.done', $item))->assertForbidden();
        $this->actingAs($other)->postJson(route('inbox.snooze', $item), ['preset' => 'tomorrow'])->assertForbidden();
        $this->actingAs($other)->postJson(route('inbox.save-thought', $item))->assertForbidden();
    }

    public function test_user_can_save_inbox_item_as_thought_and_item_is_completed(): void
    {
        $this->fakeOpenRouterForThoughtCapture();

        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'Turn this into a thought',
            'body' => 'Body content for the thought.',
            'dedupe_key' => 'save-thought-item',
        ]);

        $response = $this->actingAs($user)->post(route('inbox.save-thought', $item));

        $response->assertRedirect(route('inbox.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('thoughts', [
            'user_id' => $user->id,
            'source' => 'inbox',
        ]);

        $thought = Thought::query()->where('user_id', $user->id)->where('source', 'inbox')->first();
        $this->assertNotNull($thought);
        $this->assertSame($item->id, $thought->source_metadata['inbox_item_id']);
        $this->assertSame($item->generator_type, $thought->source_metadata['generator_type']);

        $this->assertDatabaseHas('inbox_items', [
            'id' => $item->id,
            'status' => 'done',
        ]);
        $this->assertDatabaseHas('inbox_item_actions', [
            'inbox_item_id' => $item->id,
            'action_type' => 'save_as_thought',
        ]);
    }

    public function test_save_as_thought_is_idempotent_for_a_single_item(): void
    {
        $this->fakeOpenRouterForThoughtCapture();

        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'Only save once',
            'body' => 'Duplicate submissions should not duplicate thoughts.',
            'dedupe_key' => 'save-once-item',
        ]);

        $this->actingAs($user)->post(route('inbox.save-thought', $item));
        $this->actingAs($user)->post(route('inbox.save-thought', $item));

        $this->assertSame(1, Thought::query()->where('source', 'inbox')->count());
        $this->assertSame(1, InboxItemAction::query()->where('inbox_item_id', $item->id)->where('action_type', 'save_as_thought')->count());
    }

    public function test_save_as_thought_failure_keeps_item_pending(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'Will fail',
            'body' => 'Capture should fail.',
            'dedupe_key' => 'save-failure-item',
        ]);

        $this->mock(ThoughtCaptureService::class, function ($mock): void {
            $mock->shouldReceive('create')->andThrow(new \RuntimeException('capture failed'));
        });

        $response = $this->actingAs($user)->post(route('inbox.save-thought', $item));

        $response->assertRedirect(route('inbox.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('inbox_items', [
            'id' => $item->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('inbox_item_actions', [
            'inbox_item_id' => $item->id,
            'action_type' => 'save_as_thought',
        ]);
    }

    public function test_save_as_thought_with_unusable_existing_action_returns_error_without_creating_duplicate(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'Already saving',
            'body' => 'This should not create another thought.',
            'dedupe_key' => 'save-in-progress-item',
        ]);

        InboxItemAction::query()->create([
            'inbox_item_id' => $item->id,
            'action_type' => 'save_as_thought',
            'metadata' => ['status' => 'pending'],
            'created_at' => now('UTC'),
        ]);

        $this->mock(ThoughtCaptureService::class, function ($mock): void {
            $mock->shouldNotReceive('create');
        });

        $response = $this->actingAs($user)->post(route('inbox.save-thought', $item));

        $response->assertRedirect(route('inbox.index'));
        $response->assertSessionHas('error');
        $this->assertSame(0, Thought::query()->count());
        $this->assertDatabaseHas('inbox_items', [
            'id' => $item->id,
            'status' => 'pending',
        ]);
        $this->assertSame(1, InboxItemAction::query()
            ->where('inbox_item_id', $item->id)
            ->where('action_type', 'save_as_thought')
            ->count());
    }

    public function test_save_as_thought_recovers_from_pending_action_when_matching_inbox_thought_exists(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'Recover saved thought',
            'body' => 'The thought already exists and should be linked on retry.',
            'dedupe_key' => 'save-recovery-item',
        ]);
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'inbox',
            'source_metadata' => [
                'inbox_item_id' => $item->id,
                'generator_type' => $item->generator_type,
            ],
        ]);

        $action = InboxItemAction::query()->create([
            'inbox_item_id' => $item->id,
            'action_type' => 'save_as_thought',
            'metadata' => ['status' => 'pending'],
            'created_at' => now('UTC'),
        ]);

        $this->mock(ThoughtCaptureService::class, function ($mock): void {
            $mock->shouldNotReceive('create');
        });

        $response = $this->actingAs($user)->post(route('inbox.save-thought', $item));

        $response->assertRedirect(route('inbox.index'));
        $response->assertSessionHas('success');
        $this->assertSame(1, Thought::query()->where('source', 'inbox')->count());
        $this->assertDatabaseHas('inbox_items', [
            'id' => $item->id,
            'status' => 'done',
        ]);
        $this->assertDatabaseHas('inbox_item_actions', [
            'id' => $action->id,
            'inbox_item_id' => $item->id,
            'action_type' => 'save_as_thought',
        ]);

        $action->refresh();
        $this->assertSame($thought->id, $action->metadata['thought_id'] ?? null);
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
