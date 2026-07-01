<?php

namespace Tests\Feature;

use App\Enums\ThoughtLinkType;
use App\Models\Thought;
use App\Models\ThoughtSuggestedLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtLinkSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_dismiss_marks_suggestion_dismissed(): void
    {
        config(['features.memory_graph_suggestions' => true]);

        $user = User::factory()->create();
        $from = Thought::factory()->create(['user_id' => $user->id]);
        $to = Thought::factory()->create(['user_id' => $user->id]);

        $suggestion = ThoughtSuggestedLink::query()->create([
            'user_id' => $user->id,
            'from_thought_id' => $from->id,
            'to_thought_id' => $to->id,
            'distance' => 0.32,
            'computed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('thoughts.suggestions.dismiss', [$from, $suggestion]))
            ->assertRedirect();

        $this->assertNotNull($suggestion->fresh()->dismissed_at);
    }

    public function test_promote_creates_link_and_marks_suggestion_promoted(): void
    {
        config(['features.memory_graph_suggestions' => true]);

        $user = User::factory()->create();
        $from = Thought::factory()->create(['user_id' => $user->id]);
        $to = Thought::factory()->create(['user_id' => $user->id]);

        $suggestion = ThoughtSuggestedLink::query()->create([
            'user_id' => $user->id,
            'from_thought_id' => $from->id,
            'to_thought_id' => $to->id,
            'distance' => 0.28,
            'computed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('thoughts.links.store', $from), [
                'to_thought_id' => $to->id,
                'link_type' => ThoughtLinkType::RelatesTo->value,
                'suggestion_id' => $suggestion->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('thought_links', [
            'from_thought_id' => $from->id,
            'to_thought_id' => $to->id,
            'link_type' => ThoughtLinkType::RelatesTo->value,
        ]);
        $this->assertNotNull($suggestion->fresh()->promoted_at);
    }

    public function test_suggestions_routes_404_when_flag_off(): void
    {
        config(['features.memory_graph_suggestions' => false]);

        $user = User::factory()->create();
        $from = Thought::factory()->create(['user_id' => $user->id]);
        $to = Thought::factory()->create(['user_id' => $user->id]);
        $suggestion = ThoughtSuggestedLink::query()->create([
            'user_id' => $user->id,
            'from_thought_id' => $from->id,
            'to_thought_id' => $to->id,
            'distance' => 0.3,
            'computed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('thoughts.suggestions.dismiss', [$from, $suggestion]))
            ->assertNotFound();
    }
}
