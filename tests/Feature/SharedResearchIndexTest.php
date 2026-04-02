<?php

namespace Tests\Feature;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedResearchIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_shared_research_index_excludes_hidden_email_thoughts_from_browse_dropdown(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'content' => 'HIDDEN_EMAIL_SHARED_RESEARCH_SNIPPET_X7',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => null,
            'content' => 'VISIBLE_MANUAL_SHARED_RESEARCH_SNIPPET_Y9',
            'metadata' => ['type' => 'research'],
        ]);

        $response = $this->actingAs($user)->get(route('shared-research.index'));

        $response->assertOk();
        $response->assertDontSee('HIDDEN_EMAIL_SHARED_RESEARCH_SNIPPET_X7', false);
        $response->assertSee('VISIBLE_MANUAL_SHARED_RESEARCH_SNIPPET_Y9', false);
    }

    public function test_shared_research_store_rejects_hidden_email_thoughts(): void
    {
        $user = User::factory()->create();

        $hiddenThought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'content' => 'HIDDEN_EMAIL_POST_TARGET_Q2',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $response = $this->from(route('shared-research.index'))
            ->actingAs($user)
            ->post(route('shared-research.store'), [
                'thought_id' => $hiddenThought->id,
            ]);

        $response->assertRedirect(route('shared-research.index'));
        $response->assertSessionHasErrors([
            'thought_id' => 'Only visible top-level thoughts can be shared.',
        ]);
        $this->assertDatabaseMissing('research_shares', [
            'thought_id' => $hiddenThought->id,
        ]);
    }

    public function test_shared_research_index_hides_content_for_existing_hidden_share_cards(): void
    {
        $user = User::factory()->create();

        $hiddenThought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'content' => 'HIDDEN_SHARED_CARD_SNIPPET_M4',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $share = ResearchShare::create([
            'user_id' => $user->id,
            'thought_id' => $hiddenThought->id,
            'token' => ResearchShare::generateToken(),
        ]);

        $response = $this->actingAs($user)->get(route('shared-research.index'));

        $response->assertOk();
        $response->assertSee('share-'.$share->id, false);
        $response->assertDontSee('HIDDEN_SHARED_CARD_SNIPPET_M4', false);
    }
}
