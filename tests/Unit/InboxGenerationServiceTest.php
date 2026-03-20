<?php

namespace Tests\Unit;

use App\Models\InboxItem;
use App\Models\User;
use App\Services\Inbox\InboxGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\Support\BatchOneFakeInboxGenerator;
use Tests\Support\BatchTwoFakeInboxGenerator;
use Tests\Support\FakeInboxGenerator;
use Tests\Support\ThrowingFakeInboxGenerator;
use Tests\TestCase;

class InboxGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BatchOneFakeInboxGenerator::$payloads = [];
        BatchTwoFakeInboxGenerator::$payloads = [];
    }

    public function test_respects_config_order_and_max_new_per_run(): void
    {
        Config::set('inbox.max_new_items_per_user_per_run', 5);
        Config::set('inbox.generators', [
            BatchTwoFakeInboxGenerator::class,
            BatchOneFakeInboxGenerator::class,
        ]);

        BatchOneFakeInboxGenerator::$payloads = [
            $this->payload('gen1-1', '1'),
            $this->payload('gen1-2', '2'),
            $this->payload('gen1-3', '3'),
        ];
        BatchTwoFakeInboxGenerator::$payloads = [
            $this->payload('gen2-1', '4'),
            $this->payload('gen2-2', '5'),
            $this->payload('gen2-3', '6'),
        ];

        $user = User::factory()->create();
        $created = app(InboxGenerationService::class)->generateForUser($user);

        $this->assertSame(5, $created);
        $keys = InboxItem::query()->where('user_id', $user->id)->orderBy('id')->pluck('dedupe_key')->all();
        $this->assertSame(['gen2-1', 'gen2-2', 'gen2-3', 'gen1-1', 'gen1-2'], $keys);
    }

    public function test_skips_when_pending_dedupe_key_already_exists(): void
    {
        Config::set('inbox.max_new_items_per_user_per_run', 10);
        Config::set('inbox.generators', [FakeInboxGenerator::class]);
        $this->app->bind(FakeInboxGenerator::class, fn () => new FakeInboxGenerator([
            $this->payload('dup-a', 'first'),
            $this->payload('dup-b', 'second'),
        ]));

        $user = User::factory()->create();
        InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'dup-a',
            'status' => 'pending',
            'generator_type' => 'test',
            'title' => 'existing',
            'body' => 'body',
            'generated_at' => now(),
        ]);

        $created = app(InboxGenerationService::class)->generateForUser($user);

        $this->assertSame(1, $created);
        $this->assertSame(2, InboxItem::query()->where('user_id', $user->id)->count());
        $this->assertTrue(InboxItem::query()->where('user_id', $user->id)->where('dedupe_key', 'dup-b')->exists());
    }

    public function test_snoozed_pending_item_still_blocks_duplicate(): void
    {
        Config::set('inbox.max_new_items_per_user_per_run', 10);
        Config::set('inbox.generators', [FakeInboxGenerator::class]);
        $this->app->bind(FakeInboxGenerator::class, fn () => new FakeInboxGenerator([
            $this->payload('weekly-revisit', 'weekly body'),
        ]));

        $user = User::factory()->create();
        InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'weekly-revisit',
            'status' => 'pending',
            'snoozed_until' => now()->addWeek(),
            'generator_type' => 'weekly_revisit',
            'title' => 'Snoozed',
            'body' => 'old',
            'generated_at' => now()->subDay(),
        ]);

        $created = app(InboxGenerationService::class)->generateForUser($user);

        $this->assertSame(0, $created);
        $this->assertSame(1, InboxItem::query()->where('user_id', $user->id)->count());
    }

    public function test_generator_exception_is_logged_and_other_generators_continue(): void
    {
        Log::spy();
        Config::set('inbox.max_new_items_per_user_per_run', 10);
        Config::set('inbox.generators', [
            ThrowingFakeInboxGenerator::class,
            FakeInboxGenerator::class,
        ]);
        $this->app->bind(ThrowingFakeInboxGenerator::class, fn () => new ThrowingFakeInboxGenerator(new \RuntimeException('boom')));
        $this->app->bind(FakeInboxGenerator::class, fn () => new FakeInboxGenerator([
            $this->payload('after-fail', 'ok'),
        ]));

        $user = User::factory()->create();
        $created = app(InboxGenerationService::class)->generateForUser($user);

        $this->assertSame(1, $created);
        Log::shouldHaveReceived('error')->withArgs(function (mixed $message, array $context): bool {
            return is_string($message) && str_contains($message, 'boom');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $dedupeKey, string $suffix): array
    {
        return [
            'generator_type' => 'test',
            'title' => 'Title '.$suffix,
            'body' => 'Body '.$suffix,
            'dedupe_key' => $dedupeKey,
            'generated_at' => now(),
            'source_data' => ['test' => true],
        ];
    }
}
