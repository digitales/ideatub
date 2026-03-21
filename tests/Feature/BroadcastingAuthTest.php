<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class BroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
            'broadcasting.connections.pusher.options' => [
                'host' => 'localhost',
                'port' => 6001,
                'scheme' => 'http',
                'useTLS' => false,
            ],
        ]);

        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    public function test_user_can_authorize_own_private_channel(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-App.Models.User.' . $user->id,
        ]);

        $response->assertStatus(200);
    }

    public function test_user_cannot_authorize_another_users_channel(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $response = $this->actingAs($user1)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-App.Models.User.' . $user2->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_guest_cannot_authorize_channel(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-App.Models.User.' . $user->id,
        ]);

        $response->assertStatus(401);
    }
}
