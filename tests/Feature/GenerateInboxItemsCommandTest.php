<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\FakeInboxGenerator;
use Tests\TestCase;

class GenerateInboxItemsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_generation_for_all_users(): void
    {
        Config::set('inbox.max_new_items_per_user_per_run', 10);
        Config::set('inbox.generators', [FakeInboxGenerator::class]);
        $this->app->bind(FakeInboxGenerator::class, fn () => new FakeInboxGenerator([
            [
                'generator_type' => 'test',
                'title' => 'T',
                'body' => 'B',
                'dedupe_key' => 'cmd-dedupe-1',
                'generated_at' => now(),
                'source_data' => null,
            ],
        ]));

        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->artisan('inbox:generate')->assertSuccessful();

        $this->assertSame(1, InboxItem::query()->where('user_id', $a->id)->count());
        $this->assertSame(1, InboxItem::query()->where('user_id', $b->id)->count());
        $this->assertTrue(InboxItem::query()->where('user_id', $a->id)->where('dedupe_key', 'cmd-dedupe-1')->exists());
        $this->assertTrue(InboxItem::query()->where('user_id', $b->id)->where('dedupe_key', 'cmd-dedupe-1')->exists());
    }

    public function test_command_uses_default_max_new_per_run_from_config(): void
    {
        Config::set('inbox.max_new_items_per_user_per_run', 2);
        Config::set('inbox.generators', [FakeInboxGenerator::class]);
        $this->app->bind(FakeInboxGenerator::class, fn () => new FakeInboxGenerator([
            [
                'generator_type' => 'test',
                'title' => '1',
                'body' => 'b',
                'dedupe_key' => 'k1',
                'generated_at' => now(),
                'source_data' => null,
            ],
            [
                'generator_type' => 'test',
                'title' => '2',
                'body' => 'b',
                'dedupe_key' => 'k2',
                'generated_at' => now(),
                'source_data' => null,
            ],
            [
                'generator_type' => 'test',
                'title' => '3',
                'body' => 'b',
                'dedupe_key' => 'k3',
                'generated_at' => now(),
                'source_data' => null,
            ],
        ]));

        $user = User::factory()->create();
        $this->artisan('inbox:generate')->assertSuccessful();

        $this->assertSame(2, InboxItem::query()->where('user_id', $user->id)->count());
    }

    public function test_command_prevents_duplicates_across_repeated_runs(): void
    {
        Config::set('inbox.max_new_items_per_user_per_run', 10);
        Config::set('inbox.generators', [FakeInboxGenerator::class]);
        $this->app->bind(FakeInboxGenerator::class, fn () => new FakeInboxGenerator([
            [
                'generator_type' => 'test',
                'title' => 'Repeated',
                'body' => 'B',
                'dedupe_key' => 'repeat-dedupe',
                'generated_at' => now(),
                'source_data' => null,
            ],
        ]));

        $user = User::factory()->create();

        $this->artisan('inbox:generate')->assertSuccessful();
        $this->artisan('inbox:generate')->assertSuccessful();

        $this->assertSame(1, InboxItem::query()->where('user_id', $user->id)->count());
        $this->assertTrue(InboxItem::query()->where('user_id', $user->id)->where('dedupe_key', 'repeat-dedupe')->exists());
    }

    public function test_command_treats_snoozed_pending_items_as_duplicate_blocks(): void
    {
        Config::set('inbox.max_new_items_per_user_per_run', 10);
        Config::set('inbox.generators', [FakeInboxGenerator::class]);
        $this->app->bind(FakeInboxGenerator::class, fn () => new FakeInboxGenerator([
            [
                'generator_type' => 'test',
                'title' => 'Repeated',
                'body' => 'B',
                'dedupe_key' => 'snoozed-dedupe',
                'generated_at' => now(),
                'source_data' => null,
            ],
        ]));

        $user = User::factory()->create();
        InboxItem::factory()->create([
            'user_id' => $user->id,
            'generator_type' => 'test',
            'title' => 'Existing snoozed',
            'body' => 'B',
            'dedupe_key' => 'snoozed-dedupe',
            'status' => 'pending',
            'snoozed_until' => now()->addDay(),
            'generated_at' => now()->subHour(),
        ]);

        $this->artisan('inbox:generate')->assertSuccessful();

        $this->assertSame(1, InboxItem::query()->where('user_id', $user->id)->count());
        $this->assertSame(
            'Existing snoozed',
            InboxItem::query()->where('user_id', $user->id)->where('dedupe_key', 'snoozed-dedupe')->value('title')
        );
    }
}
