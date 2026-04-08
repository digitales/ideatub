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
            'label' => 'Created in IdeaTub',
        ]);
    }

    public function test_user_can_create_mcp_key_with_custom_label(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.mcp-keys.store'), [
            'label' => '  Cursor laptop  ',
        ]);

        $response->assertRedirect(route('settings.mcp-keys.index'));
        $response->assertSessionHas('new_mcp_key');

        $this->assertDatabaseHas('user_mcp_keys', [
            'user_id' => $user->id,
            'label' => 'Cursor laptop',
        ]);
    }

    public function test_user_can_create_mcp_key_blank_label_uses_default(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.mcp-keys.store'), [
            'label' => '   ',
        ]);

        $response->assertRedirect(route('settings.mcp-keys.index'));

        $this->assertDatabaseHas('user_mcp_keys', [
            'user_id' => $user->id,
            'label' => 'Created in IdeaTub',
        ]);
    }

    public function test_create_mcp_key_rejects_label_over_max_length(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.mcp-keys.store'), [
            'label' => str_repeat('a', 65),
        ]);

        $response->assertSessionHasErrors('label');
        $this->assertDatabaseCount('user_mcp_keys', 0);
    }

    public function test_user_can_update_own_mcp_key_label(): void
    {
        $user = User::factory()->create();
        $key = UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey('ideatub_test_key_'.str_repeat('c', 32)),
            'label' => 'Created in IdeaTub',
        ]);

        $field = 'label_'.$key->id;

        $response = $this->actingAs($user)->patch(route('settings.mcp-keys.update', $key), [
            $field => 'Work machine',
        ]);

        $response->assertRedirect(route('settings.mcp-keys.index'));
        $response->assertSessionHas('success');

        $key->refresh();
        $this->assertSame('Work machine', $key->label);
    }

    public function test_user_can_clear_mcp_key_label_to_default_on_update(): void
    {
        $user = User::factory()->create();
        $key = UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey('ideatub_test_key_'.str_repeat('d', 32)),
            'label' => 'Temporary',
        ]);

        $field = 'label_'.$key->id;

        $response = $this->actingAs($user)->patch(route('settings.mcp-keys.update', $key), [
            $field => '  ',
        ]);

        $response->assertRedirect(route('settings.mcp-keys.index'));

        $key->refresh();
        $this->assertSame('Created in IdeaTub', $key->label);
    }

    public function test_update_mcp_key_label_rejects_over_max_length(): void
    {
        $user = User::factory()->create();
        $key = UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey('ideatub_test_key_'.str_repeat('e', 32)),
            'label' => 'Ok',
        ]);

        $field = 'label_'.$key->id;

        $response = $this->actingAs($user)->patch(route('settings.mcp-keys.update', $key), [
            $field => str_repeat('x', 65),
        ]);

        $response->assertSessionHasErrors($field);

        $key->refresh();
        $this->assertSame('Ok', $key->label);
    }

    public function test_user_cannot_update_another_users_mcp_key_label(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $key = UserMcpKey::query()->create([
            'user_id' => $otherUser->id,
            'key_hash' => UserMcpKey::hashKey('ideatub_test_key_'.str_repeat('f', 32)),
            'label' => 'Other',
        ]);

        $field = 'label_'.$key->id;

        $response = $this->actingAs($user)->patch(route('settings.mcp-keys.update', $key), [
            $field => 'Hacked',
        ]);

        $response->assertForbidden();

        $key->refresh();
        $this->assertSame('Other', $key->label);
    }

    public function test_mcp_keys_page_shows_masked_key_not_full_secret_for_existing_keys(): void
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('z', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
            'label' => 'Zed',
        ]);

        $response = $this->actingAs($user)->get(route('settings.mcp-keys.index'));

        $response->assertStatus(200);
        $response->assertSee('ideatub_••••', false);
        $response->assertDontSee($plain);
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
        $plainKey = 'ideatub_'.str_repeat('a', 32);
        $user->userMcpKeys()->create([
            'key_hash' => UserMcpKey::hashKey($plainKey),
            'label' => 'Test key',
        ]);

        $response = $this->postJson('/api/mcp?key='.$plainKey, [
            'jsonrpc' => '2.0',
            'method' => 'thought_stats',
            'id' => 1,
        ]);

        $response->assertStatus(401);
    }
}
