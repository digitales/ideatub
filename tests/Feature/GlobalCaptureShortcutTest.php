<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalCaptureShortcutTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_page_includes_global_capture_shell(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('data-placement="global"', false);
        $response->assertSee('ideatub-global-capture', false);
    }

    public function test_home_page_does_not_include_global_capture_shell(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertDontSee('ideatub-global-capture', false);
    }
}
