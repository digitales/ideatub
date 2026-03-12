<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserInboundAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundEmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_redirected_to_login(): void
    {
        $response = $this->get(route('settings.inbound-emails.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_page(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $response = $this->actingAs($user)->get(route('settings.inbound-emails.index'));

        $response->assertStatus(200);
        $response->assertSee('Inbound email');
        $response->assertSee('user@example.com');
        $response->assertSee('Add address');
    }

    public function test_user_can_add_inbound_address(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.inbound-emails.store'), [
            'email' => '  capture@example.com  ',
        ]);

        $response->assertRedirect(route('settings.inbound-emails.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('user_inbound_addresses', [
            'user_id' => $user->id,
            'email' => 'capture@example.com',
        ]);
    }

    public function test_user_can_remove_inbound_address(): void
    {
        $user = User::factory()->create();
        $address = UserInboundAddress::create(['user_id' => $user->id, 'email' => 'remove@example.com']);

        $response = $this->actingAs($user)->delete(route('settings.inbound-emails.destroy', $address));

        $response->assertRedirect(route('settings.inbound-emails.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('user_inbound_addresses', ['id' => $address->id]);
    }

    public function test_user_cannot_remove_other_users_address(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $addressB = UserInboundAddress::create(['user_id' => $userB->id, 'email' => 'other@example.com']);

        $response = $this->actingAs($userA)->delete(route('settings.inbound-emails.destroy', $addressB));

        $response->assertStatus(403);
        $this->assertDatabaseHas('user_inbound_addresses', ['id' => $addressB->id]);
    }

    public function test_duplicate_email_rejected(): void
    {
        $userA = User::factory()->create();
        UserInboundAddress::create(['user_id' => $userA->id, 'email' => 'taken@example.com']);
        $userB = User::factory()->create();

        $response = $this->actingAs($userB)->post(route('settings.inbound-emails.store'), [
            'email' => 'taken@example.com',
        ]);

        $response->assertRedirect(route('settings.inbound-emails.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('user_inbound_addresses', 1);
    }
}
