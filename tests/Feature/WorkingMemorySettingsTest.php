<?php

namespace Tests\Feature;

use App\Jobs\ConsolidateWorkingMemory;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\WorkingMemory\WorkingMemoryConsolidationWindowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

class WorkingMemorySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_redirected_to_login(): void
    {
        $response = $this->get(route('settings.working-memory.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_get_returns_200(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.working-memory.index'));

        $response->assertOk();
        $response->assertSee('Working memory', false);
        $response->assertSee('Consolidation window', false);
        $response->assertSee('Save preferences', false);
    }

    public function test_put_sets_consolidation_window_preference(): void
    {
        config(['working_memory.consolidation_window_days' => 180]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('settings.working-memory.index'))
            ->put(route('settings.working-memory.update'), [
                'working_memory_consolidation_window_days' => 45,
            ]);

        $response->assertRedirect(route('settings.working-memory.index'));
        $response->assertSessionHas('success', 'Working memory settings saved.');

        $this->assertSame(
            45,
            (int) UserPreference::get($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS)
        );
        $this->assertSame(
            45,
            app(WorkingMemoryConsolidationWindowResolver::class)->effectiveDaysForUserId((int) $user->id)
        );
    }

    public function test_put_with_empty_clears_preference(): void
    {
        config(['working_memory.consolidation_window_days' => 90]);
        $user = User::factory()->create();
        UserPreference::set($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS, 30);

        $response = $this->actingAs($user)
            ->from(route('settings.working-memory.index'))
            ->put(route('settings.working-memory.update'), [
                'working_memory_consolidation_window_days' => '',
            ]);

        $response->assertRedirect(route('settings.working-memory.index'));
        $response->assertSessionHas('success', 'Working memory settings saved.');

        $this->assertFalse(
            UserPreference::query()
                ->where('user_id', $user->id)
                ->where('key', UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS)
                ->exists()
        );
        $this->assertSame(
            90,
            app(WorkingMemoryConsolidationWindowResolver::class)->effectiveDaysForUserId((int) $user->id)
        );
    }

    public function test_put_sets_forced_tags_preference(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('settings.working-memory.index'))
            ->put(route('settings.working-memory.update'), [
                'working_memory_forced_tags' => " AI,\nml,AI ",
            ]);

        $response->assertRedirect(route('settings.working-memory.index'));
        $response->assertSessionHas('success', 'Working memory settings saved.');

        $this->assertSame(
            ['ai', 'ml'],
            UserPreference::get($user, UserPreference::KEY_WORKING_MEMORY_FORCED_TAGS)
        );
    }

    public function test_put_with_empty_forced_tags_clears_preference(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, UserPreference::KEY_WORKING_MEMORY_FORCED_TAGS, ['ai', 'ml']);

        $response = $this->actingAs($user)
            ->from(route('settings.working-memory.index'))
            ->put(route('settings.working-memory.update'), [
                'working_memory_forced_tags' => '',
            ]);

        $response->assertRedirect(route('settings.working-memory.index'));
        $response->assertSessionHas('success', 'Working memory settings saved.');

        $this->assertFalse(
            UserPreference::query()
                ->where('user_id', $user->id)
                ->where('key', UserPreference::KEY_WORKING_MEMORY_FORCED_TAGS)
                ->exists()
        );
    }

    public function test_build_now_queues_consolidation_for_saved_forced_tags(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserPreference::set($user, UserPreference::KEY_WORKING_MEMORY_FORCED_TAGS, ['ai', 'ml']);

        $response = $this->actingAs($user)
            ->post(route('settings.working-memory.build-now'));

        $response->assertRedirect(route('settings.working-memory.index'));
        $response->assertSessionHas('success', 'Queued working memory build for 2 forced tags.');

        Queue::assertPushed(ConsolidateWorkingMemory::class, 2);
        Queue::assertPushed(ConsolidateWorkingMemory::class, fn (ConsolidateWorkingMemory $job): bool => $this->matchesJobScope($job, $user->id, 'tag', 'ai'));
        Queue::assertPushed(ConsolidateWorkingMemory::class, fn (ConsolidateWorkingMemory $job): bool => $this->matchesJobScope($job, $user->id, 'tag', 'ml'));
    }

    public function test_build_now_with_no_forced_tags_sets_error_message(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('settings.working-memory.build-now'));

        $response->assertRedirect(route('settings.working-memory.index'));
        $response->assertSessionHas('error', 'No forced tags saved yet. Add tags and save preferences first.');
        Queue::assertNotPushed(ConsolidateWorkingMemory::class);
    }

    private function matchesJobScope(ConsolidateWorkingMemory $job, int $userId, string $scopeType, string $scopeKey): bool
    {
        $reflection = new ReflectionClass($job);

        return $reflection->getProperty('userId')->getValue($job) === $userId
            && $reflection->getProperty('scopeType')->getValue($job) === $scopeType
            && $reflection->getProperty('scopeKey')->getValue($job) === $scopeKey;
    }
}
