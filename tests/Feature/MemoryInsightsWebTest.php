<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryInsightsWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_get_memory_insights_redirects_to_login(): void
    {
        config(['features.working_memory_insights' => true]);

        $response = $this->get(route('memory.insights'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_insights_flag_off_returns_404(): void
    {
        config(['features.working_memory_insights' => false]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('memory.insights'));

        $response->assertNotFound();
    }

    public function test_authenticated_insights_flag_on_returns_200(): void
    {
        config(['features.working_memory_insights' => true]);
        $user = User::factory()->create();

        Thought::factory()
            ->for($user)
            ->create([
                'metadata' => [
                    'type' => 'research',
                    'tags' => ['strategy', 'markets'],
                ],
                'content' => 'A longer research capture title that should appear in notable captures section for the user.',
            ]);

        $response = $this->actingAs($user)->get(route('memory.insights'));

        $response->assertOk();
        $response->assertSee('Memory insights', false);
        $response->assertSee('Themes', false);
        $response->assertSee('Notable captures', false);
        $response->assertSee('strategy', false);
    }
}
