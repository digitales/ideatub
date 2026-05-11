<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Models\User;
use App\Services\DemoMode;
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

    public function test_research_show_shows_related_video_when_linked_via_source_metadata(): void
    {
        $user = User::factory()->create();
        $video = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Video root',
            'metadata' => [
                'type' => 'video',
                'video_id' => 'dQw4w9WgXcQ',
                'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'transcript_status' => 'available',
                'transcript_source' => 'youtube',
            ],
        ]);
        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $video->id,
            'content' => "## Summary\n\nFrom video.",
            'source' => 'research',
            'metadata' => [
                'type' => 'research',
                'tags' => ['research', 'video'],
                'video_thought_id' => $video->id,
                'video_section_type' => 'research',
            ],
            'source_metadata' => [
                'video_thought_id' => $video->id,
                'video_id' => 'dQw4w9WgXcQ',
                'transcript_context_available' => true,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $research));

        $response->assertOk();
        $response->assertSee('Related video', false);
        $response->assertSee('Video metadata', false);
        $response->assertSee('Video ID', false);
        $response->assertSee('dQw4w9WgXcQ', false);
        $response->assertSee('Transcript available', false);
        $response->assertSee('Open video thought', false);
        $response->assertSee(route('thoughts.show', $video), false);
        $response->assertSee('https://www.youtube.com/watch?v=dQw4w9WgXcQ', false);
    }

    public function test_research_show_does_not_redirect_when_thought_is_child_of_video_root(): void
    {
        $user = User::factory()->create();
        $video = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Video root',
            'metadata' => [
                'type' => 'video',
                'video_id' => 'vidChildResearch',
                'video_url' => 'https://www.youtube.com/watch?v=vidChildResearch',
                'transcript_status' => 'available',
                'transcript_source' => 'youtube',
            ],
        ]);
        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $video->id,
            'content' => "## Summary\n\nVideo child research unique body vchild-no-redirect-1.",
            'source' => 'research',
            'metadata' => [
                'type' => 'research',
                'tags' => ['research', 'video'],
                'video_thought_id' => $video->id,
                'video_section_type' => 'research',
            ],
            'source_metadata' => [
                'video_thought_id' => $video->id,
                'video_id' => 'vidChildResearch',
                'transcript_context_available' => true,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $research));

        $response->assertOk();
        $response->assertSee('Video child research unique body vchild-no-redirect-1', false);
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

    public function test_thoughts_show_redirects_to_research_reader_for_microsite_root(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '---\ntitle: Index\n---\n# Home',
            'source_metadata' => [
                'document_layout' => 'microsite',
                'page_path_segment' => 'index',
                'import_order' => 0,
            ],
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('thoughts.show', $root));

        $response->assertRedirect(route('idea.research.show', $root));
    }

    public function test_thoughts_show_redirects_to_research_page_for_microsite_child(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Index',
            'source_metadata' => [
                'document_layout' => 'microsite',
                'page_path_segment' => 'index',
                'import_order' => 0,
            ],
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
        ]);
        $child = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => '# Executive summary',
            'source_metadata' => [
                'document_layout' => 'microsite',
                'page_path_segment' => '01-executive-summary',
                'import_order' => 1,
                'microsite_root_id' => (string) $root->id,
            ],
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('thoughts.show', $child));

        $response->assertRedirect(route('idea.research.page', [
            'thought' => $root,
            'page' => '01-executive-summary',
        ]));
    }

    public function test_research_show_microsite_nav_sorts_by_numeric_import_order_not_string_order(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => "# Home index\n\nIndex body.",
            'source_metadata' => [
                'document_layout' => 'microsite',
                'page_path_segment' => 'index',
                'import_order' => 0,
            ],
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => '# Zebra ten',
            'source_metadata' => [
                'document_layout' => 'microsite',
                'page_path_segment' => 'p10',
                'import_order' => '10',
                'microsite_root_id' => (string) $root->id,
            ],
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => '# Alpha one',
            'source_metadata' => [
                'document_layout' => 'microsite',
                'page_path_segment' => 'p1',
                'import_order' => '1',
                'microsite_root_id' => (string) $root->id,
            ],
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => '# Beta two',
            'source_metadata' => [
                'document_layout' => 'microsite',
                'page_path_segment' => 'p2',
                'import_order' => '2',
                'microsite_root_id' => (string) $root->id,
            ],
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $root));
        $response->assertOk();
        $response->assertSeeInOrder(
            [
                'Home index',
                'Alpha one',
                'Beta two',
                'Zebra ten',
            ],
            false
        );
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

    public function test_research_show_renders_editorial_link_summaries_grouped_by_newsletter_section_order(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Newsletter body for editorial sections',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => "# Research with editorial links\n\nBody.",
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [
                'email_thought_id' => $emailThought->id,
                'email_subject' => 'Subject for section order test',
                'email_sender' => 'editorial-sections@example.com',
            ],
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/section-order-b',
            'normalized_url' => 'https://example.com/section-order-b',
            'normalized_url_hash' => sha1('https://example.com/section-order-b'),
            'newsletter_section_label' => 'Later newsletter section',
            'newsletter_section_order' => 2,
            'classification' => 'editorial',
            'processing_status' => 'summarized',
            'resolved_title' => 'Title section order B unique elso-b',
            'summary_text' => 'Summary B unique elso-b-sum',
            'support_judgment' => 'supports',
            'why_it_matters' => 'Why B matters unique elso-b-why',
            'usefulness_score' => 5,
            'section_rank' => 1,
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/section-order-a',
            'normalized_url' => 'https://example.com/section-order-a',
            'normalized_url_hash' => sha1('https://example.com/section-order-a'),
            'newsletter_section_label' => 'Earlier newsletter section',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'summarized',
            'resolved_title' => 'Title section order A unique elso-a',
            'summary_text' => 'Summary A unique elso-a-sum',
            'support_judgment' => 'neutral',
            'why_it_matters' => 'Why A matters unique elso-a-why',
            'usefulness_score' => 5,
            'section_rank' => 1,
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertSee('Editorial link summaries', false);
        $response->assertSeeInOrder([
            'Earlier newsletter section',
            'Later newsletter section',
        ], false);
    }

    public function test_research_show_shows_pending_count_for_unsummarized_editorial_links(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Newsletter for pending count',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research pending editorial',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [
                'email_thought_id' => $emailThought->id,
            ],
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/pending-one',
            'normalized_url' => 'https://example.com/pending-one',
            'normalized_url_hash' => sha1('https://example.com/pending-one'),
            'newsletter_section_label' => 'Main',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'queued',
            'resolved_title' => null,
            'summary_text' => null,
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/pending-fetching',
            'normalized_url' => 'https://example.com/pending-fetching',
            'normalized_url_hash' => sha1('https://example.com/pending-fetching'),
            'newsletter_section_label' => 'Main',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'fetching',
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertSee('Editorial link summaries', false);
        $response->assertSee('2', false);
        $response->assertSee('pending', false);
    }

    public function test_research_show_orders_editorial_items_within_section_by_usefulness_score_descending(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Newsletter for usefulness order',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research usefulness order',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [
                'email_thought_id' => $emailThought->id,
            ],
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/usefulness-low',
            'normalized_url' => 'https://example.com/usefulness-low',
            'normalized_url_hash' => sha1('https://example.com/usefulness-low'),
            'newsletter_section_label' => 'Single section usefulness',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'summarized',
            'resolved_title' => 'Lower score title unique uscore-low',
            'summary_text' => 'Low summary unique uscore-low-sum',
            'usefulness_score' => 3,
            'section_rank' => 1,
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/usefulness-high',
            'normalized_url' => 'https://example.com/usefulness-high',
            'normalized_url_hash' => sha1('https://example.com/usefulness-high'),
            'newsletter_section_label' => 'Single section usefulness',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'summarized',
            'resolved_title' => 'Higher score title unique uscore-high',
            'summary_text' => 'High summary unique uscore-high-sum',
            'usefulness_score' => 9,
            'section_rank' => 2,
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertNotFalse($content);
        $posHigh = mb_strpos($content, 'Higher score title unique uscore-high');
        $posLow = mb_strpos($content, 'Lower score title unique uscore-low');
        $this->assertNotFalse($posHigh);
        $this->assertNotFalse($posLow);
        $this->assertLessThan($posLow, $posHigh, 'Higher usefulness item should appear before lower in HTML');
    }

    public function test_research_show_orders_editorial_items_by_section_rank_when_usefulness_score_ties(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Newsletter for rank tie-break',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research rank tie',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [
                'email_thought_id' => $emailThought->id,
            ],
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/rank-second',
            'normalized_url' => 'https://example.com/rank-second',
            'normalized_url_hash' => sha1('https://example.com/rank-second'),
            'newsletter_section_label' => 'Rank tie section',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'summarized',
            'resolved_title' => 'Second rank title unique srank-2',
            'summary_text' => 'Second summary',
            'usefulness_score' => 7,
            'section_rank' => 2,
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/rank-first',
            'normalized_url' => 'https://example.com/rank-first',
            'normalized_url_hash' => sha1('https://example.com/rank-first'),
            'classification' => 'editorial',
            'processing_status' => 'summarized',
            'resolved_title' => 'First rank title unique srank-1',
            'summary_text' => 'First summary',
            'newsletter_section_label' => 'Rank tie section',
            'newsletter_section_order' => 1,
            'usefulness_score' => 7,
            'section_rank' => 1,
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertNotFalse($content);
        $posFirst = mb_strpos($content, 'First rank title unique srank-1');
        $posSecond = mb_strpos($content, 'Second rank title unique srank-2');
        $this->assertNotFalse($posFirst);
        $this->assertNotFalse($posSecond);
        $this->assertLessThan($posSecond, $posFirst);
    }

    public function test_research_show_excludes_noise_and_sponsor_from_editorial_summary_block(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Newsletter for classification filter',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research filter noise sponsor',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [
                'email_thought_id' => $emailThought->id,
            ],
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/noise-row',
            'normalized_url' => 'https://example.com/noise-row',
            'normalized_url_hash' => sha1('https://example.com/noise-row'),
            'newsletter_section_label' => 'Noise sec',
            'newsletter_section_order' => 1,
            'classification' => 'noise',
            'processing_status' => 'summarized',
            'resolved_title' => 'NOISE_UNIQUE_SHOULD_NOT_RENDER_9911',
            'summary_text' => 'Noise summary should not show 9911',
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/sponsor-row',
            'normalized_url' => 'https://example.com/sponsor-row',
            'normalized_url_hash' => sha1('https://example.com/sponsor-row'),
            'newsletter_section_label' => 'Sponsor sec',
            'newsletter_section_order' => 2,
            'classification' => 'sponsor',
            'processing_status' => 'summarized',
            'resolved_title' => 'SPONSOR_UNIQUE_SHOULD_NOT_RENDER_9922',
            'summary_text' => 'Sponsor summary should not show 9922',
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/editorial-kept',
            'normalized_url' => 'https://example.com/editorial-kept',
            'normalized_url_hash' => sha1('https://example.com/editorial-kept'),
            'newsletter_section_label' => 'Editorial only section',
            'newsletter_section_order' => 3,
            'classification' => 'editorial',
            'processing_status' => 'summarized',
            'resolved_title' => 'EDITORIAL_VISIBLE_UNIQUE_9933',
            'summary_text' => 'Editorial summary visible 9933',
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertSee('EDITORIAL_VISIBLE_UNIQUE_9933', false);
        $response->assertDontSee('NOISE_UNIQUE_SHOULD_NOT_RENDER_9911', false);
        $response->assertDontSee('SPONSOR_UNIQUE_SHOULD_NOT_RENDER_9922', false);
    }

    public function test_research_show_renders_quality_notes_for_editorial_summaries(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Newsletter for quality notes',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research quality notes',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [
                'email_thought_id' => $emailThought->id,
            ],
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/quality-notes',
            'normalized_url' => 'https://example.com/quality-notes',
            'normalized_url_hash' => sha1('https://example.com/quality-notes'),
            'newsletter_section_label' => 'Qn section',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'summarized',
            'resolved_title' => 'Title with quality notes unique qn-8844',
            'summary_text' => 'Body summary qn',
            'quality_notes' => 'QUALITY_NOTES_UNIQUE_SUBDUED_8844',
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertSee('QUALITY_NOTES_UNIQUE_SUBDUED_8844', false);
    }

    public function test_research_show_renders_why_it_matters_inline_without_template_whitespace(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Newsletter for why it matters formatting',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research why it matters formatting',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [
                'email_thought_id' => $emailThought->id,
            ],
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/why-inline',
            'normalized_url' => 'https://example.com/why-inline',
            'normalized_url_hash' => sha1('https://example.com/why-inline'),
            'newsletter_section_label' => 'Why inline section',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'summarized',
            'resolved_title' => 'Why inline title unique wiw-1122',
            'summary_text' => 'Summary body unique wiw-1122',
            'why_it_matters' => 'WHY_IT_MATTERS_INLINE_UNIQUE_1122',
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertNotFalse($content);
        $this->assertStringContainsString(
            'Why it matters:</span> WHY_IT_MATTERS_INLINE_UNIQUE_1122',
            $content
        );
    }

    public function test_research_show_shows_failed_count_for_failed_editorial_links(): void
    {
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'Newsletter for failed count',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => '# Research failed editorial',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [
                'email_thought_id' => $emailThought->id,
            ],
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/failed-one',
            'normalized_url' => 'https://example.com/failed-one',
            'normalized_url_hash' => sha1('https://example.com/failed-one'),
            'newsletter_section_label' => 'F',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'failed',
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertSee('Editorial link summaries', false);
        $response->assertSee('1', false);
        $response->assertSee('failed', false);
    }

    public function test_demo_mode_obfuscates_private_research_page_narrative_content(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $owner = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'source' => 'email',
            'content' => 'EMAIL_BODY_SHOULD_NOT_APPEAR_HERE',
        ]);

        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'embedding' => null,
            'content' => "# DEMO_RESEARCH_ROOT_SECRET_TITLE\n\nDEMO_RESEARCH_ROOT_SECRET_BODY",
            'metadata' => [
                'type' => 'research',
                'tags' => [],
                'email_thought_id' => $emailThought->id,
                'email_subject' => 'DEMO_RELATED_EMAIL_SUBJECT_SECRET',
                'email_sender' => 'visible-sender@example.com',
            ],
        ]);

        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $root->id,
            'embedding' => null,
            'content' => "## DEMO_RESEARCH_SECTION_SECRET_TITLE\n\nDEMO_RESEARCH_SECTION_SECRET_BODY",
        ]);

        $this->createThoughtLinkSummaryRow($owner->id, $emailThought->id, $root->id, [
            'original_url' => 'https://example.com/demo-editorial',
            'normalized_url' => 'https://example.com/demo-editorial',
            'normalized_url_hash' => sha1('https://example.com/demo-editorial'),
            'newsletter_section_label' => 'Main section',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'summarized',
            'resolved_title' => 'DEMO_EDITORIAL_TITLE_SECRET',
            'summary_text' => 'DEMO_EDITORIAL_SUMMARY_SECRET',
            'support_judgment' => 'supports',
            'why_it_matters' => 'DEMO_EDITORIAL_WHY_SECRET',
            'quality_notes' => 'DEMO_EDITORIAL_QUALITY_SECRET',
        ]);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'research-show-demo-seed',
        ])->actingAs($owner)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertSee('Demo mode enabled. Sensitive text is obfuscated.', false);
        $response->assertDontSee('DEMO_RESEARCH_ROOT_SECRET_TITLE', false);
        $response->assertDontSee('DEMO_RESEARCH_ROOT_SECRET_BODY', false);
        $response->assertDontSee('DEMO_RESEARCH_SECTION_SECRET_TITLE', false);
        $response->assertDontSee('DEMO_RESEARCH_SECTION_SECRET_BODY', false);
        $response->assertDontSee('DEMO_RELATED_EMAIL_SUBJECT_SECRET', false);
        $response->assertDontSee('DEMO_EDITORIAL_TITLE_SECRET', false);
        $response->assertDontSee('DEMO_EDITORIAL_SUMMARY_SECRET', false);
        $response->assertDontSee('DEMO_EDITORIAL_WHY_SECRET', false);
        $response->assertDontSee('DEMO_EDITORIAL_QUALITY_SECRET', false);
        $response->assertSee('visible-sender@example.com', false);
        $response->assertSee('https://example.com/demo-editorial', false);
        $response->assertSee('supports', false);
        $response->assertSee('Main section', false);
    }

    public function test_research_show_displays_project_when_associated(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# With project',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);
        $project = \App\Models\Project::factory()->create([
            'user_id' => $user->id,
            'title' => 'Test Project',
        ]);
        $thought->projects()->attach($project, ['sort_order' => 0]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $thought));

        $response->assertStatus(200);
        $response->assertSee('Test Project', false);
    }

    public function test_research_show_displays_tags(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Tagged research',
            'metadata' => ['type' => 'research', 'tags' => ['ai', 'ml']],
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $thought));

        $response->assertStatus(200);
        $response->assertSee('ai', false);
        $response->assertSee('ml', false);
    }

    public function test_research_show_displays_title_from_metadata(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Body content here.',
            'metadata' => ['type' => 'research', 'tags' => [], 'title' => 'My Custom Title'],
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $thought));

        $response->assertStatus(200);
        $response->assertSee('My Custom Title', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createThoughtLinkSummaryRow(string $userId, string $sourceThoughtId, string $parentResearchThoughtId, array $overrides): ThoughtLinkSummary
    {
        $defaults = [
            'user_id' => $userId,
            'source_thought_id' => $sourceThoughtId,
            'parent_research_thought_id' => $parentResearchThoughtId,
            'source_type' => 'email_newsletter',
            'original_url' => 'https://example.com/default',
            'normalized_url' => 'https://example.com/default',
            'normalized_url_hash' => sha1('https://example.com/default-'.uniqid('', true)),
            'classification' => 'editorial',
            'processing_status' => 'queued',
        ];

        return ThoughtLinkSummary::query()->create(array_merge($defaults, $overrides));
    }
}
