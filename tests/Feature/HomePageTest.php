<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_homepage_does_not_show_evernote(): void
    {
        $response = $this->get(route('home'));

        $response->assertStatus(200);
        $response->assertDontSee('Evernote');
    }

    public function test_guest_homepage_shows_mcp_and_integration_language(): void
    {
        $response = $this->get(route('home'));

        $response->assertStatus(200);
        $response->assertSee('Use the web app or connect via MCP.');
        $response->assertSee('Claude');
        $response->assertSee('Cursor');
    }
}
