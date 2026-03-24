<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsIdeasSectionNav;
use Tests\TestCase;

class IdeasToRevisitPageTest extends TestCase
{
    use AssertsIdeasSectionNav;
    use RefreshDatabase;

    public function test_guest_redirected_to_login(): void
    {
        $response = $this->get(route('idea.revisit'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_empty_state_when_no_incomplete_ideas(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true, 'logged_date' => '2025-01-01'],
        ]);
        Thought::factory()->create(['user_id' => $user->id, 'metadata' => ['type' => 'note']]);

        $response = $this->actingAs($user)->get(route('idea.revisit'));

        $response->assertStatus(200);
        $response->assertSee('Ideas to revisit');
        $response->assertSee('No ideas to revisit');
    }

    public function test_revisit_hides_idea_with_completed_at_even_if_completed_flag_false(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Has timestamp only',
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'logged_date' => '2025-01-01',
                'completed_at' => now()->toIso8601String(),
            ],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.revisit'));

        $response->assertStatus(200);
        $response->assertSee('No ideas to revisit');
        $response->assertDontSee('Has timestamp only');
    }

    public function test_revisit_page_is_directly_accessible_and_shows_shared_secondary_nav_with_revisit_active(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.revisit'));

        $response->assertStatus(200);
        $this->assertIdeasSectionNav($response, 'revisit');
    }

    public function test_authenticated_user_sees_list_when_incomplete_ideas_exist(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'An incomplete idea to revisit',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-02-15'],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.revisit'));

        $response->assertStatus(200);
        $response->assertSee('Ideas to revisit');
        $response->assertSee('An incomplete idea to revisit');
        $response->assertSee('2025-02-15');
    }

    public function test_revisit_page_respects_ideas_to_revisit_limit_preference(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 2);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Oldest idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Middle idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-02-01'],
            'embedding' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Newest idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-03-01'],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.revisit'));

        $response->assertStatus(200);
        $response->assertSee('Oldest idea');
        $response->assertSee('Middle idea');
        $response->assertDontSee('Newest idea');
    }

    public function test_settings_ideas_revisit_guest_redirected(): void
    {
        $response = $this->get(route('settings.ideas-revisit.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_settings_ideas_revisit_get_shows_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.ideas-revisit.index'));

        $response->assertStatus(200);
        $response->assertSee('Ideas to revisit');
        $response->assertSee('Maximum number of ideas');
        $response->assertSee('Minimum age');
        $response->assertSee('Save preferences');
    }

    public function test_settings_ideas_revisit_put_updates_and_redirects(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('settings.ideas-revisit.update'), [
            'ideas_to_revisit_limit' => 10,
            'ideas_to_revisit_min_age_days' => 3,
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('settings.ideas-revisit.index'));
        $response->assertSessionHas('success', 'Ideas to revisit preferences saved.');

        $this->assertSame(10, UserPreference::get($user, 'ideas_to_revisit_limit'));
        $this->assertSame(3, UserPreference::get($user, 'ideas_to_revisit_min_age_days'));
    }
}
