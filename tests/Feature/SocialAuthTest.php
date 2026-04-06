<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_github_callback_with_null_email_redirects_to_login_with_error(): void
    {
        $fakeGithubUser = (object) [
            'id' => '12345',
            'name' => 'Test User',
            'nickname' => 'testuser',
            'email' => null,
        ];

        Socialite::shouldReceive('driver->user')->andReturn($fakeGithubUser);

        $response = $this->get('/auth/github/callback');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_github_callback_with_null_email_does_not_create_user(): void
    {
        $fakeGithubUser = (object) [
            'id' => '99999',
            'name' => 'Ghost User',
            'nickname' => 'ghost',
            'email' => null,
        ];

        Socialite::shouldReceive('driver->user')->andReturn($fakeGithubUser);

        $this->get('/auth/github/callback');

        $this->assertDatabaseMissing('users', ['github_id' => '99999']);
    }
}
