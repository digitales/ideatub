<?php

namespace Tests\Feature;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Tests\TestCase;

class SharedResearchControllerTest extends TestCase
{
    public function test_store_requires_authentication(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'parent_id' => null]);

        $response = $this->post(route('shared-research.store'), [
            'thought_id' => $thought->id,
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('login', $response->headers->get('Location'));
    }

    public function test_store_validates_thought_id_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('shared-research.store'), [
            '_token' => csrf_token(),
        ]);

        $response->assertSessionHasErrors('thought_id');
    }

    public function test_store_rejects_other_users_thought(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'content' => 'Owner only research',
        ]);

        $response = $this->actingAs($other)->post(route('shared-research.store'), [
            'thought_id' => $thought->id,
            '_token' => csrf_token(),
        ]);

        $response->assertForbidden();
    }

    public function test_store_rejects_non_root_thought(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $user->id, 'parent_id' => null]);
        $child = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => 'Child thought',
        ]);

        $response = $this->actingAs($user)->post(route('shared-research.store'), [
            'thought_id' => $child->id,
            '_token' => csrf_token(),
        ]);

        $response->assertForbidden();
    }

    public function test_store_creates_share_and_redirects_with_share_param(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'My root research',
        ]);

        $response = $this->actingAs($user)->post(route('shared-research.store'), [
            'thought_id' => $thought->id,
            '_token' => csrf_token(),
        ]);

        $share = ResearchShare::where('thought_id', $thought->id)->first();
        $this->assertNotNull($share);
        $response->assertRedirect(route('shared-research.index', ['share' => $share->id]));
        $response->assertSessionHas('success', 'Link created. Copy below.');
        $this->assertDatabaseHas('research_shares', [
            'thought_id' => $thought->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_store_second_create_for_same_thought_redirects_with_already_shared(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
        ]);
        ResearchShare::create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
            'token' => ResearchShare::generateToken(),
            'password_hash' => null,
            'expires_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('shared-research.store'), [
            'thought_id' => $thought->id,
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('shared-research.index'));
        $response->assertSessionHas('error', 'This research is already shared; manage it below.');
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->get(route('shared-research.index'));

        $response->assertRedirect();
        $this->assertStringContainsString('login', $response->headers->get('Location'));
    }

    public function test_index_lists_only_current_users_shares(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $thoughtA = Thought::factory()->create([
            'user_id' => $userA->id,
            'parent_id' => null,
            'content' => 'Only A sees this',
        ]);
        $thoughtB = Thought::factory()->create([
            'user_id' => $userB->id,
            'parent_id' => null,
            'content' => 'Only B sees this',
        ]);
        $shareA = ResearchShare::create([
            'user_id' => $userA->id,
            'thought_id' => $thoughtA->id,
            'token' => ResearchShare::generateToken(),
            'password_hash' => null,
            'expires_at' => null,
        ]);
        ResearchShare::create([
            'user_id' => $userB->id,
            'thought_id' => $thoughtB->id,
            'token' => ResearchShare::generateToken(),
            'password_hash' => null,
            'expires_at' => null,
        ]);

        $response = $this->actingAs($userA)->get(route('shared-research.index'));

        $response->assertStatus(200);
        $response->assertSee('Only A sees this', false);
        $response->assertDontSee('Only B sees this', false);
        $response->assertSee($shareA->token, false);
    }

    public function test_destroy_revokes_share_then_show_returns_404(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
        ]);
        $share = ResearchShare::create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
            'token' => ResearchShare::generateToken(),
            'password_hash' => null,
            'expires_at' => null,
        ]);

        $response = $this->actingAs($user)->delete(route('shared-research.destroy', $share));

        $response->assertRedirect(route('shared-research.index'));
        $response->assertSessionHas('success', 'Share revoked.');
        $this->assertDatabaseMissing('research_shares', ['id' => $share->id]);

        $showResponse = $this->get(route('shared-research.show', ['token' => $share->token]));
        $showResponse->assertStatus(404);
    }

    public function test_update_password_then_cookie_invalidated_on_password_change(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Secret content',
        ]);
        $share = ResearchShare::create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
            'token' => ResearchShare::generateToken(),
            'password_hash' => null,
            'expires_at' => null,
        ]);

        // Initially no password: GET shows content
        $this->get(route('shared-research.show', ['token' => $share->token]))
            ->assertStatus(200)
            ->assertSee('Secret content', false);

        // Owner sets password via PATCH
        $this->actingAs($user)->patch(route('shared-research.update', $share), [
            'password' => 'first',
            '_token' => csrf_token(),
        ])->assertRedirect();

        // GET without cookie shows password form
        $this->get(route('shared-research.show', ['token' => $share->token]))
            ->assertStatus(200)
            ->assertSee('Enter password to view', false)
            ->assertDontSee('Secret content', false);

        // POST correct password -> cookie set
        $cookieName = 'research_share_'.$share->token;
        $postResponse = $this->post(route('shared-research.show', ['token' => $share->token]), [
            'password' => 'first',
            '_token' => csrf_token(),
        ]);
        $postResponse->assertRedirect(route('shared-research.show', ['token' => $share->token]));
        $this->assertNotNull($postResponse->getCookie($cookieName));

        // GET with cookie shows content
        $this->withCookie($cookieName, $share->token)
            ->get(route('shared-research.show', ['token' => $share->token]))
            ->assertStatus(200)
            ->assertSee('Secret content', false);

        // Owner changes password via PATCH (response must send forget cookie so client drops it)
        $patchResponse = $this->actingAs($user)->patch(route('shared-research.update', $share), [
            'password' => 'second',
            '_token' => csrf_token(),
        ]);
        $patchResponse->assertRedirect();
        $cookies = $patchResponse->headers->getCookies();
        $forgetCookie = collect($cookies)->first(fn ($c) => $c->getName() === 'research_share_'.$share->token);
        $this->assertNotNull($forgetCookie, 'PATCH response should send forget cookie for this share');

        // After password change, GET without the cookie must show password form (cookie was invalidated)
        $this->defaultCookies = []; // clear so we don't resend the old cookie; real client would have applied forget
        $getAfterChange = $this->get(route('shared-research.show', ['token' => $share->token]));
        $getAfterChange->assertStatus(200);
        $getAfterChange->assertSee('Enter password to view', false);
        $getAfterChange->assertDontSee('Secret content', false);
    }
}
