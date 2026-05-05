<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\User;
use App\Models\UserPreference;
use App\Services\WorkingMemory\WorkingMemoryConsolidationWindowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryConsolidationWindowResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_uses_config_when_preference_missing(): void
    {
        config(['working_memory.consolidation_window_days' => 90]);
        $user = User::factory()->create();

        $days = app(WorkingMemoryConsolidationWindowResolver::class)->effectiveDaysForUserId((int) $user->id);

        $this->assertSame(90, $days);
    }

    #[Test]
    public function it_uses_numeric_preference_when_set(): void
    {
        config(['working_memory.consolidation_window_days' => 90]);
        $user = User::factory()->create();
        UserPreference::set($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS, 45);

        $days = app(WorkingMemoryConsolidationWindowResolver::class)->effectiveDaysForUserId((int) $user->id);

        $this->assertSame(45, $days);
    }
}
