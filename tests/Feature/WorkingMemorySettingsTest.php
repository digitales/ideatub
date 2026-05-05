<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPreference;
use App\Services\WorkingMemory\WorkingMemoryConsolidationWindowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
