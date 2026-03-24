<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResearchShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Blade layout loads Vite entrypoints; a fresh worktree may lack public/build/manifest.json.
        $this->withoutVite();
    }

    public function test_research_show_requires_authentication(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Research doc',
        ]);

        $response = $this->get(route('idea.research.show', $thought));

        $response->assertRedirect(route('login'));
    }

    public function test_research_show_renders_formatted_markdown_for_owner(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => "# Research title\n\nSome **bold** content.",
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $thought));

        $response->assertStatus(200);
        $response->assertSee('Research title', false);
        $response->assertSee('bold', false);
        $response->assertSee('Back to Stream', false);
        $response->assertSee('Research', false);
    }

    public function test_research_show_still_renders_all_sections_after_shared_partial_refactor(): void
    {
        $user = User::factory()->create();
        $rootBody = 'Research full-page guardrail root body unique rsrch-guard-1.';
        $sectionBodies = [
            'Research full-page guardrail section one unique rsrch-guard-2.',
            'Research full-page guardrail section two unique rsrch-guard-3.',
            'Research full-page guardrail section three unique rsrch-guard-4.',
        ];

        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => "# Guardrail research title\n\n{$rootBody}",
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
        ]);

        foreach ($sectionBodies as $body) {
            Thought::factory()->create([
                'user_id' => $user->id,
                'parent_id' => $root->id,
                'content' => "## Section\n\n{$body}",
            ]);
        }

        $response = $this->actingAs($user)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertSee($rootBody, false);
        foreach ($sectionBodies as $body) {
            $response->assertSee($body, false);
        }
    }

    public function test_research_show_redirects_to_parent_when_viewing_child_thought(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Root research',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
        ]);
        $child = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => 'Section two',
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $child));

        $response->assertRedirect(route('idea.research.show', $root));
    }

    public function test_research_show_returns_403_for_other_users_thought(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'content' => 'Private research',
        ]);

        $response = $this->actingAs($other)->get(route('idea.research.show', $thought));

        $response->assertStatus(403);
    }

    public function test_research_show_includes_related_email_card_when_root_source_metadata_links_complete_email(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Newsletter email body',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => "# Research from newsletter\n\nBody.",
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [
                'email_thought_id' => $emailThought->id,
                'email_subject' => 'Fresh newsletter subject',
                'email_sender' => 'newsletter-write-path@example.com',
            ],
        ]);

        $emailHref = route('thoughts.show', $emailThought);
        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertSee('Fresh newsletter subject', false);
        $response->assertSee('newsletter-write-path@example.com', false);
        $response->assertSee('View email', false);
        $response->assertSee($emailHref, false);
    }

    public function test_research_show_includes_related_email_card_when_root_metadata_links_complete_email(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Linked email body',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => "# Research with email\n\nBody.",
            'metadata' => [
                'type' => 'research',
                'tags' => [],
                'email_thought_id' => $emailThought->id,
                'email_subject' => 'Original newsletter subject',
                'email_sender' => 'newsletter@example.com',
            ],
        ]);

        $emailHref = route('thoughts.show', $emailThought);
        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertSee('Original newsletter subject', false);
        $response->assertSee('newsletter@example.com', false);
        $response->assertSee('View email', false);
        $response->assertSee($emailHref, false);
    }

    public function test_research_show_omits_related_email_card_when_linked_email_thought_is_missing(): void
    {
        $owner = User::factory()->create();
        $missingEmailId = (string) Str::uuid();

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research missing email',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
                'email_thought_id' => $missingEmailId,
                'email_subject' => 'Orphan subject',
                'email_sender' => 'orphan@example.com',
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertDontSee('View email', false);
        $response->assertDontSee('Orphan subject', false);
    }

    public function test_research_show_omits_related_email_card_when_linked_email_thought_belongs_to_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $otherEmailThought = Thought::factory()->create([
            'user_id' => $other->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Other user email',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research with foreign email id',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
                'email_thought_id' => $otherEmailThought->id,
                'email_subject' => 'Should not leak',
                'email_sender' => 'leak@example.com',
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertDontSee('View email', false);
        $response->assertDontSee('Should not leak', false);
        $response->assertDontSee('leak@example.com', false);
    }

    public function test_research_show_omits_related_email_card_when_email_subject_is_missing_from_metadata(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Email no subject meta',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research incomplete subject',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
                'email_thought_id' => $emailThought->id,
                'email_sender' => 'sender@example.com',
            ],
        ]);

        $emailHref = route('thoughts.show', $emailThought);
        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertDontSee('View email', false);
        $response->assertDontSee('sender@example.com', false);
        $response->assertDontSee($emailHref, false);
    }

    public function test_research_show_omits_related_email_card_when_email_sender_is_missing_from_metadata(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Email no sender meta',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research incomplete sender',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
                'email_thought_id' => $emailThought->id,
                'email_subject' => 'Subject only',
            ],
        ]);

        $emailHref = route('thoughts.show', $emailThought);
        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertDontSee('View email', false);
        $response->assertDontSee('Subject only', false);
        $response->assertDontSee($emailHref, false);
    }

    public function test_research_show_redirects_to_thought_detail_when_root_is_not_research(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'web',
            'content' => '# Not research\n\nRegular thought body.',
            'metadata' => null,
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $thought));

        $response->assertRedirect(route('thoughts.show', $thought));

        $detail = $this->actingAs($owner)->get(route('thoughts.show', $thought));
        $detail->assertOk();
        $detail->assertSee('Regular thought body', false);
        $detail->assertSee('Thought detail', false);
        $detail->assertDontSee('← Back to Stream', false);
    }

    public function test_research_show_omits_related_email_card_when_email_thought_id_points_to_non_email_thought(): void
    {
        $owner = User::factory()->create();
        $nonEmailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'web',
            'content' => 'Plain web capture',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research with bad email link',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
                'email_thought_id' => $nonEmailThought->id,
                'email_subject' => 'Metadata subject line',
                'email_sender' => 'metadata@example.com',
            ],
        ]);

        $wrongHref = route('thoughts.show', $nonEmailThought);
        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertDontSee('View email', false);
        $response->assertDontSee('Metadata subject line', false);
        $response->assertDontSee('metadata@example.com', false);
        $response->assertDontSee($wrongHref, false);
    }
}
