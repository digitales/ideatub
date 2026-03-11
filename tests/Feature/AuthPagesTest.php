<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthPagesTest extends TestCase
{
    public function test_login_page_shows_brand_identity(): void
    {
        $response = $this->get(route('login'));

        $response->assertStatus(200);
        $response->assertSee('IdeaTub');
        $response->assertSee('Sign in');
        $response->assertSee('Google');
        $response->assertSee('GitHub');
    }

    public function test_register_page_shows_brand_identity(): void
    {
        $response = $this->get(route('register'));

        $response->assertStatus(200);
        $response->assertSee('IdeaTub');
        $response->assertSee('Create your account');
        $response->assertSee('Google');
        $response->assertSee('GitHub');
    }
}
