<?php

// Markdown rendering safety audit — Task 10 (Phase 2), audited 2026-04-23.
// Pins CommonMark renderer config (html_input=strip, allow_unsafe_links=false)
// used by IdeaController::show so raw HTML and javascript: URLs never reach
// the thought detail response. See docs/superpowers/plans/2026-04-22-file-folder-upload.md.

namespace Tests\Feature\Rendering;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkdownSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_raw_html_is_not_rendered_in_thought_detail(): void
    {
        $user = User::factory()->create();
        $thought = Thought::create([
            'user_id' => $user->id,
            'content' => "Hello <script>alert('x')</script> world",
            'source' => 'test',
        ]);

        $this->actingAs($user)
            ->get(route('thoughts.show', $thought))
            ->assertStatus(200)
            ->assertDontSee('<script>alert', false);
    }

    public function test_javascript_url_is_neutralised_in_markdown_links(): void
    {
        $user = User::factory()->create();
        $thought = Thought::create([
            'user_id' => $user->id,
            'content' => '[click](javascript:alert(1))',
            'source' => 'test',
        ]);

        $response = $this->actingAs($user)
            ->get(route('thoughts.show', $thought));

        $response->assertStatus(200);
        $response->assertDontSee('href="javascript:', false);
    }
}
