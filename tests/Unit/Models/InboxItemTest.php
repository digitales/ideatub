<?php

namespace Tests\Unit\Models;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxItemTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function actionable_scope_excludes_done_and_future_snoozed_items(): void
    {
        $user = User::factory()->create();

        $actionable = InboxItem::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'snoozed_until' => null,
            'dedupe_key' => 'actionable-item',
        ]);

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'status' => 'done',
            'dedupe_key' => 'done-item',
        ]);

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'snoozed_until' => now()->addDay(),
            'dedupe_key' => 'future-snoozed-item',
        ]);

        $results = InboxItem::query()->forUser($user)->actionable()->get();

        $this->assertCount(1, $results);
        $this->assertSame($actionable->id, $results->first()->id);
    }

    #[Test]
    public function active_scope_keeps_future_snoozed_pending_items(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'dedupe_key' => 'weekly-revisit',
            'snoozed_until' => now()->addWeek(),
        ]);

        $results = InboxItem::query()->forUser($user)->active()->get();

        $this->assertCount(1, $results);
        $this->assertSame('weekly-revisit', $results->first()->dedupe_key);
    }

    #[Test]
    public function factory_generates_a_unique_default_dedupe_key_for_each_pending_item(): void
    {
        $user = User::factory()->create();

        $first = InboxItem::factory()->create([
            'user_id' => $user->id,
        ]);

        $second = InboxItem::factory()->create([
            'user_id' => $user->id,
        ]);

        $this->assertNotSame($first->dedupe_key, $second->dedupe_key);
    }

    #[Test]
    public function actionable_scope_includes_past_snoozed_items_once_due(): void
    {
        $user = User::factory()->create();

        $dueItem = InboxItem::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'dedupe_key' => 'past-snoozed-item',
            'snoozed_until' => now()->subMinute(),
        ]);

        $results = InboxItem::query()->forUser($user)->actionable()->get();

        $this->assertCount(1, $results);
        $this->assertSame($dueItem->id, $results->first()->id);
    }
}
