<?php

namespace Tests\Unit\Models;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AchievementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_scope_active_excludes_retired_and_scope_tagged_filters_by_tag(): void
    {
        $user = User::factory()->create();
        Achievement::factory()->for($user)->create(['tag' => 'laravel', 'retired_at' => null]);
        Achievement::factory()->for($user)->create(['tag' => 'laravel', 'retired_at' => now()]);
        Achievement::factory()->for($user)->create(['tag' => 'leadership', 'retired_at' => null]);

        $this->assertCount(2, Achievement::query()->active()->get());
        $this->assertCount(1, Achievement::query()->active()->tagged('laravel')->get());
    }
}
