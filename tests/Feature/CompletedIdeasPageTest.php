<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\AssertsIdeasSectionNav;
use Tests\TestCase;

class CompletedIdeasPageTest extends TestCase
{
    use AssertsIdeasSectionNav;
    use RefreshDatabase;

    public function test_guest_redirected_to_login(): void
    {
        $response = $this->get(route('idea.completed'));

        $response->assertRedirect(route('login'));
    }

    public function test_completed_page_shows_only_completed_ideas(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Still open',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Done and dusted',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-02-01',
                'completed_at' => '2026-03-10T10:00:00+00:00',
            ],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.completed'));

        $response->assertStatus(200);
        $response->assertSee('Done and dusted');
        $response->assertDontSee('Still open');
    }

    public function test_completed_page_lists_idea_with_completed_at_when_completed_flag_is_false(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Active incomplete',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Timestamp only completed',
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'logged_date' => '2025-02-01',
                'completed_at' => '2026-03-10T10:00:00+00:00',
            ],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.completed'));

        $response->assertStatus(200);
        $response->assertSee('Timestamp only completed');
        $response->assertDontSee('Active incomplete');
    }

    public function test_completed_page_is_scoped_to_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'My completed idea',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-02-01',
                'completed_at' => '2026-03-10T10:00:00+00:00',
            ],
            'embedding' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $otherUser->id,
            'content' => 'Other user completed idea',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-02-02',
                'completed_at' => '2026-03-11T10:00:00+00:00',
            ],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.completed'));

        $response->assertStatus(200);
        $response->assertSee('My completed idea');
        $response->assertDontSee('Other user completed idea');
    }

    public function test_completed_page_orders_newest_timestamped_first_then_legacy_last(): void
    {
        $user = User::factory()->create();

        $legacy = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Legacy completed',
            'metadata' => ['type' => 'idea', 'completed' => true, 'logged_date' => '2025-01-01'],
            'embedding' => null,
            'updated_at' => Carbon::parse('2026-03-01 10:00:00'),
        ]);
        $older = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Older timestamp',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-02-01',
                'completed_at' => '2026-02-01T10:00:00+00:00',
            ],
            'embedding' => null,
        ]);
        $newer = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Newer timestamp',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-03-01',
                'completed_at' => '2026-03-10T10:00:00+00:00',
            ],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.completed'));

        $response->assertStatus(200);
        $this->assertSame(
            [$newer->id, $older->id, $legacy->id],
            $this->completedIdeaIdsInOrder($response->getContent())
        );
    }

    public function test_completed_page_renders_completed_date_text(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'With completion time',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-04-01',
                'completed_at' => '2026-03-24T15:30:00+00:00',
            ],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.completed'));

        $response->assertStatus(200);
        $response->assertSee('April 1, 2025', false);
        $response->assertSee('March 24, 2026', false);
        $response->assertDontSee('2025-04-01');
        $response->assertSee('Logged April 1, 2025', false);
        $response->assertSee('Completed March 24, 2026', false);
    }

    public function test_completed_page_treats_malformed_completed_at_like_legacy_row_and_shows_fallback_marker(): void
    {
        $user = User::factory()->create();

        $malformed = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Malformed completed timestamp',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-04-01',
                'completed_at' => '2026-13-40T10:00:00+00:00',
            ],
            'embedding' => null,
            'updated_at' => Carbon::parse('2026-03-11 10:00:00'),
        ]);
        $timestamped = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Valid timestamp row',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-04-02',
                'completed_at' => '2026-03-12T10:00:00+00:00',
            ],
            'embedding' => null,
            'updated_at' => Carbon::parse('2026-03-10 10:00:00'),
        ]);

        $response = $this->actingAs($user)->get(route('idea.completed'));

        $response->assertStatus(200);
        $this->assertSame(
            [$timestamped->id, $malformed->id],
            $this->completedIdeaIdsInOrder($response->getContent())
        );
        $response->assertSee('Completed —', false);
    }

    public function test_completed_page_empty_state(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.completed'));

        $response->assertStatus(200);
        $response->assertSee('No completed ideas yet.');
    }

    public function test_completed_page_shows_shared_nav_with_completed_active(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.completed'));

        $response->assertStatus(200);
        $this->assertIdeasSectionNav($response, 'completed');
    }

    public function test_completed_page_does_not_show_inline_reopen_control(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Listed completed no reopen btn',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-05-01',
                'completed_at' => '2026-03-24T16:00:00+00:00',
            ],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.completed'));

        $response->assertStatus(200);
        $response->assertSee('Listed completed no reopen btn');
        $response->assertSee(route('thoughts.show', $thought), false);
        $response->assertDontSee('Mark as incomplete', false);
        $response->assertDontSee(route('ideas.toggle-completed', $thought), false);
    }

    /**
     * @return list<string>
     */
    private function completedIdeaIdsInOrder(string $html): array
    {
        preg_match_all('/data-completed-idea-id="([^"]+)"/', $html, $matches);

        return $matches[1];
    }

    public function test_demo_mode_obfuscates_completed_excerpts_while_preserving_date_labels(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'IDEATUB_FEATURE_COMPLETED_EXCERPT_SECRET',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-04-01',
                'completed_at' => '2026-03-24T15:30:00+00:00',
            ],
            'embedding' => null,
        ]);

        $demo = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'feat-seed-completed-ideas-demo',
        ])->actingAs($user);

        $page = $demo->get(route('idea.completed'));
        $page->assertOk();
        $page->assertSee('Demo mode enabled. Sensitive text is obfuscated.', false);
        $page->assertDontSee('IDEATUB_FEATURE_COMPLETED_EXCERPT_SECRET', false);
        $page->assertSee('April 1, 2025', false);
        $page->assertSee('March 24, 2026', false);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $normal = $this->actingAs($user)->get(route('idea.completed'));
        $normal->assertOk();
        $normal->assertSee('IDEATUB_FEATURE_COMPLETED_EXCERPT_SECRET', false);
    }
}
