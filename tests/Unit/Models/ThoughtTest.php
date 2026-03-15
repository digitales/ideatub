<?php

namespace Tests\Unit\Models;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_ideas_returns_only_thoughts_with_type_idea(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create(['user_id' => $user->id, 'metadata' => null]);
        Thought::factory()->create(['user_id' => $user->id, 'metadata' => ['type' => 'note']]);
        $idea = Thought::factory()->create(['user_id' => $user->id, 'metadata' => ['type' => 'idea']]);

        $ideas = Thought::query()->where('user_id', $user->id)->ideas()->get();

        $this->assertCount(1, $ideas);
        $this->assertTrue($ideas->first()->is($idea));
    }

    public function test_logged_date_returns_metadata_logged_date_or_created_at_date(): void
    {
        $user = User::factory()->create();

        $withLogged = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'logged_date' => '2025-03-10'],
        ]);
        $withoutLogged = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);

        $this->assertSame('2025-03-10', $withLogged->getLoggedDate());
        $this->assertSame($withoutLogged->created_at->toDateString(), $withoutLogged->getLoggedDate());
    }

    public function test_is_idea_completed_returns_true_only_when_metadata_completed_is_true(): void
    {
        $user = User::factory()->create();

        $completed = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true],
        ]);
        $incomplete = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false],
        ]);
        $noFlag = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);

        $this->assertTrue($completed->isIdeaCompleted());
        $this->assertFalse($incomplete->isIdeaCompleted());
        $this->assertFalse($noFlag->isIdeaCompleted());
    }

    public function test_content_is_normalized_on_save_html_entities_decoded(): void
    {
        $user = User::factory()->create();

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => "Daphne&#039;s breathing was 30 per minute.",
        ]);

        $this->assertSame("Daphne's breathing was 30 per minute.", $thought->content);
        $this->assertSame("Daphne's breathing was 30 per minute.", $thought->getDecodedContent());
    }

    public function test_decode_content_entities_handles_double_encoding(): void
    {
        $this->assertSame("Daphne's", Thought::decodeContentEntities("Daphne&amp;#039;s"));
        $this->assertSame("foo \"bar\"", Thought::decodeContentEntities("foo &quot;bar&quot;"));
    }

    public function test_decode_content_entities_handles_numeric_entity_without_semicolon(): void
    {
        // PHP's html_entity_decode does not decode &#039s (no semicolon); we normalize so it decodes.
        $this->assertSame("Daphne's breathing", Thought::decodeContentEntities("Daphne&#039s breathing"));
        $this->assertSame("Daphne's", Thought::decodeContentEntities("Daphne&#039;s"));
    }
}
