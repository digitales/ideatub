<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingMemoryWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_get_memory_redirects_to_login(): void
    {
        config(['features.working_memory_ui' => true]);

        $response = $this->get(route('memory.show'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_flag_off_returns_404(): void
    {
        config(['features.working_memory_ui' => false]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('memory.show'));

        $response->assertNotFound();
    }

    public function test_authenticated_flag_on_returns_200_and_shows_title(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('memory.show'));

        $response->assertOk();
        $response->assertSee('Working memory', false);
        $response->assertSee('Details', false);
    }
}
