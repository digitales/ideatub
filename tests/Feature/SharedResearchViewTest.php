<?php

namespace Tests\Feature;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Tests\TestCase;

class SharedResearchViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_unknown_token_returns_404(): void
    {
        $response = $this->get('/r/'.str_repeat('a', 32));

        $response->assertStatus(404);
    }

    public function test_share_without_password_shows_readonly_content(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'research'],
            'content' => 'Root research content',
        ]);
        $share = ResearchShare::create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
            'token' => ResearchShare::generateToken(),
            'password_hash' => null,
            'expires_at' => null,
        ]);

        $response = $this->get(route('shared-research.show', ['token' => $share->token]));

        $response->assertStatus(200);
        $response->assertSee('Root research content', false);
        $response->assertSee('Research', false);
        $response->assertSee('Shared via IdeaTub', false);
    }

    public function test_readonly_meeting_root_shows_meeting_label(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'meeting'],
            'content' => 'Standup notes body',
        ]);
        $share = ResearchShare::create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
            'token' => ResearchShare::generateToken(),
            'password_hash' => null,
            'expires_at' => null,
        ]);

        $response = $this->get(route('shared-research.show', ['token' => $share->token]));

        $response->assertOk();
        $response->assertSee('Meeting', false);
        $response->assertSee('Standup notes body', false);
    }

    public function test_password_protected_share_get_shows_password_form(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Secret research',
        ]);
        $share = ResearchShare::create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
            'token' => ResearchShare::generateToken(),
            'password_hash' => bcrypt('secret'),
            'expires_at' => null,
        ]);

        $response = $this->get(route('shared-research.show', ['token' => $share->token]));

        $response->assertStatus(200);
        $response->assertSee('Enter password to view', false);
        $response->assertDontSee('Secret research', false);
    }

    public function test_password_protected_share_post_wrong_password_returns_401(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Secret research',
        ]);
        $share = ResearchShare::create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
            'token' => ResearchShare::generateToken(),
            'password_hash' => bcrypt('correct'),
            'expires_at' => null,
        ]);

        $response = $this->post(route('shared-research.show', ['token' => $share->token]), [
            'password' => 'wrong',
            '_token' => csrf_token(),
        ]);

        $response->assertStatus(401);
        $response->assertSee('Incorrect password', false);
    }

    public function test_password_protected_share_post_correct_password_redirects_with_cookie(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Secret research',
        ]);
        $share = ResearchShare::create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
            'token' => ResearchShare::generateToken(),
            'password_hash' => bcrypt('correct'),
            'expires_at' => null,
        ]);

        $response = $this->post(route('shared-research.show', ['token' => $share->token]), [
            'password' => 'correct',
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('shared-research.show', ['token' => $share->token]));
        $cookieName = 'research_share_'.$share->token;
        $this->assertNotNull($response->getCookie($cookieName));
    }
}
