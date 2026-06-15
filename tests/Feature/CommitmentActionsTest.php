<?php

namespace Tests\Feature;

use App\Models\CommitmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommitmentActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_mark_commitment_done(): void
    {
        config(['features.attention_pulse' => true]);
        $user = User::factory()->create();
        $item = CommitmentItem::query()->create([
            'user_id' => $user->id,
            'type' => 'meeting_action',
            'status' => 'open',
            'title' => 'Follow up',
            'dedupe_key' => 'test:done:1',
            'opened_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('commitments.done', $item))
            ->assertRedirect(route('pulse.show'));

        $this->assertSame('done', $item->fresh()->status);
        $this->assertNotNull($item->fresh()->closed_at);
    }

    public function test_user_can_snooze_commitment(): void
    {
        config(['features.attention_pulse' => true]);
        $user = User::factory()->create();
        $item = CommitmentItem::query()->create([
            'user_id' => $user->id,
            'type' => 'meeting_action',
            'status' => 'open',
            'title' => 'Follow up',
            'dedupe_key' => 'test:snooze:1',
            'opened_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('commitments.snooze', $item), ['preset' => 'tomorrow'])
            ->assertRedirect(route('pulse.show'));

        $this->assertNotNull($item->fresh()->snoozed_until);
    }

    public function test_other_user_cannot_update_commitment(): void
    {
        config(['features.attention_pulse' => true]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $item = CommitmentItem::query()->create([
            'user_id' => $owner->id,
            'type' => 'meeting_action',
            'status' => 'open',
            'title' => 'Follow up',
            'dedupe_key' => 'test:forbidden:1',
            'opened_at' => now(),
        ]);

        $this->actingAs($other)
            ->post(route('commitments.done', $item))
            ->assertForbidden();
    }
}
