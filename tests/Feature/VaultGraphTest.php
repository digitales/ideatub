<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VaultGraphTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_vault_graph_truncates_at_limit(): void
    {
        config(['features.memory_graph_vault' => true]);

        $user = User::factory()->create();
        Thought::factory()->count(5)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson(route('graph.vault.data', ['limit' => 3]));

        $response->assertOk();
        $response->assertJsonPath('meta.truncated', true);
        $response->assertJsonCount(3, 'nodes');
    }

    public function test_vault_graph_404_when_flag_off(): void
    {
        config(['features.memory_graph_vault' => false]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('graph.vault'))
            ->assertNotFound();
    }
}
