<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMcpKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpKeySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mcp_keys_page_requires_auth(): void
    {
        $response = $this->get(route('settings.mcp-keys.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_mcp_keys_page_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.mcp-keys.index'));

        $response->assertStatus(200);
        $response->assertSee('MCP key');
        $response->assertSee('Create MCP key');
    }

    public function test_user_can_create_mcp_key(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.mcp-keys.store'));

        $response->assertRedirect(route('settings.mcp-keys.index'));
        $response->assertSessionHas('new_mcp_key');

        $plainKey = $response->getSession()->get('new_mcp_key');
        $this->assertIsString($plainKey);
        $this->assertStringStartsWith('ideatub_', $plainKey);
        $this->assertSame(40, strlen($plainKey)); // ideatub_ + 32 chars

        $this->assertDatabaseHas('user_mcp_keys', [
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_revoke_own_mcp_key(): void
    {
        $user = User::factory()->create();
        $key = UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey('ideatub_test_key_'.str_repeat('a', 32)),
        ]);

        $response = $this->actingAs($user)->delete(route('settings.mcp-keys.destroy', $key));

        $response->assertRedirect(route('settings.mcp-keys.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('user_mcp_keys', ['id' => $key->id]);
    }

    public function test_user_cannot_revoke_another_users_mcp_key(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $key = UserMcpKey::query()->create([
            'user_id' => $otherUser->id,
            'key_hash' => UserMcpKey::hashKey('ideatub_test_key_'.str_repeat('b', 32)),
        ]);

        $response = $this->actingAs($user)->delete(route('settings.mcp-keys.destroy', $key));

        $response->assertForbidden();
        $this->assertDatabaseHas('user_mcp_keys', ['id' => $key->id]);
    }

    public function test_mcp_key_in_query_string_is_rejected(): void
    {
        $user = User::factory()->create();
        $plainKey = 'ideatub_' . str_repeat('a', 32);
        $user->userMcpKeys()->create([
            'key_hash' => \App\Models\UserMcpKey::hashKey($plainKey),
            'label' => 'Test key',
        ]);

        $response = $this->postJson('/api/mcp?key=' . $plainKey, [
            'jsonrpc' => '2.0',
            'method' => 'thought_stats',
            'id' => 1,
        ]);

        $response->assertStatus(401);
    }
}
