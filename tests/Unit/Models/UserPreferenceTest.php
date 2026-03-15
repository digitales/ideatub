<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_then_get_returns_value(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 15);

        $value = UserPreference::get($user, 'ideas_to_revisit_limit');

        $this->assertSame(15, $value);
    }

    public function test_get_with_missing_key_returns_default(): void
    {
        $user = User::factory()->create();

        $this->assertNull(UserPreference::get($user, 'missing_key'));
        $this->assertSame(15, UserPreference::get($user, 'missing_key', 15));
        $this->assertSame('fallback', UserPreference::get($user, 'missing_key', 'fallback'));
    }

    public function test_set_overwrites_existing(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 10);
        UserPreference::set($user, 'ideas_to_revisit_limit', 20);

        $value = UserPreference::get($user, 'ideas_to_revisit_limit');

        $this->assertSame(20, $value);
        $this->assertSame(1, UserPreference::query()->where('user_id', $user->id)->where('key', 'ideas_to_revisit_limit')->count());
    }
}
