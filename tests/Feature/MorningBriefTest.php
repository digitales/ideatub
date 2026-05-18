<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\InboxItem;
use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MorningBriefTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_home_shows_morning_brief_with_personalized_greeting(): void
    {
        $user = User::factory()->create(['name' => 'Ross Tweedie']);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee('Morning brief', false);
        $response->assertSee('Ross', false);
    }

    public function test_morning_brief_shows_draft_inbox_and_revisit_cards_when_present(): void
    {
        $user = User::factory()->create();

        Draft::create([
            'user_id' => $user->id,
            'content' => 'Finish the pricing write-up',
            'no_chunking' => false,
        ]);

        InboxItem::factory()->for($user)->create();

        Thought::factory()->for($user)->create([
            'metadata' => ['type' => 'idea', 'completed' => false],
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee('Finish the pricing write-up', false);
        $response->assertSee('item needs attention', false);
        $response->assertSee('idea to revisit', false);
        $response->assertSee(route('inbox.index'), false);
        $response->assertSee(route('idea.revisit'), false);
    }

    public function test_morning_brief_shows_latest_project_card(): void
    {
        $user = User::factory()->create();

        Project::factory()->for($user)->create(['title' => 'IdeaTub roadmap']);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee('IdeaTub roadmap', false);
    }

    public function test_search_mode_hides_morning_brief(): void
    {
        $user = User::factory()->create(['name' => 'Ross Tweedie']);

        $response = $this->actingAs($user)->get(route('idea.index', ['q' => 'pricing']));

        $response->assertOk();
        $response->assertDontSee('Morning brief', false);
        $response->assertSee('Find a memory', false);
    }

    public function test_reply_mode_hides_morning_brief(): void
    {
        $user = User::factory()->create(['name' => 'Ross Tweedie']);
        $parent = Thought::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('idea.index', ['parent_id' => $parent->id]));

        $response->assertOk();
        $response->assertDontSee('Morning brief', false);
        $response->assertSee('A calm archive for your ideas', false);
    }
}
