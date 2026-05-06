<?php

namespace Tests\Feature;

use App\Models\Project;
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

    public function test_project_memory_other_user_returns_403(): void
    {
        config(['features.working_memory_ui' => true]);
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $response = $this->actingAs($intruder)->get(route('projects.memory.show', $project));

        $response->assertForbidden();
    }

    public function test_project_memory_owner_with_flag_returns_200(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['title' => 'Alpha Research']);

        $response = $this->actingAs($user)->get(route('projects.memory.show', $project));

        $response->assertOk();
        $response->assertSee('Working memory', false);
        $response->assertSee('Alpha Research', false);
        $response->assertSee('Details', false);
    }
}
