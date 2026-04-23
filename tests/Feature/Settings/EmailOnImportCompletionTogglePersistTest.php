<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailOnImportCompletionTogglePersistTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_disable_import_completion_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('settings.profile.notifications'), [
                'email_on_import_completion' => '0',
            ])
            ->assertRedirect();

        $pref = UserPreference::query()
            ->where('user_id', $user->id)
            ->where('key', 'email_on_import_completion')
            ->value('value');

        $this->assertSame('false', $pref);
    }
}
