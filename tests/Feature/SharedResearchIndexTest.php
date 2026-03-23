<?php

namespace Tests\Feature;

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
        ]);

        $response = $this->actingAs($user)->get(route('shared-research.index'));

        $response->assertOk();
        $response->assertDontSee('HIDDEN_EMAIL_SHARED_RESEARCH_SNIPPET_X7', false);
        $response->assertSee('VISIBLE_MANUAL_SHARED_RESEARCH_SNIPPET_Y9', false);
    }
}
