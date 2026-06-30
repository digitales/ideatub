<?php

namespace Tests\Unit\Services\Inbox;

use App\Models\InboxItem;
use App\Models\User;
use App\Services\Inbox\InboxIndexPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxIndexPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_presenter_splits_groups_and_singles(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->count(2)->create([
            'user_id' => $user->id,
            'generator_type' => 'wm_fallback',
            'status' => 'pending',
            'snoozed_until' => null,
        ]);

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'generator_type' => 'weekly_revisit',
            'dedupe_key' => 'weekly-single',
            'status' => 'pending',
            'snoozed_until' => null,
        ]);

        $presenter = app(InboxIndexPresenter::class);
        $viewModel = $presenter->present($user);

        $this->assertCount(1, $viewModel['groups']);
        $this->assertSame('wm_fallback', $viewModel['groups']->first()->generatorType);
        $this->assertSame(2, $viewModel['groups']->first()->items->count());
        $this->assertSame(1, $viewModel['singles']->total());
        $this->assertSame(3, $viewModel['inboxInitialCount']);
    }
}
