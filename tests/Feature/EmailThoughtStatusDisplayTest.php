<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\DemoMode;
use App\Services\DemoObfuscationGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailThoughtStatusDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_email_thought_research_queued_shows_queued_status_on_index_and_stream(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Email queued for research',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_queued',
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertSee('data-email-research-status="research_queued"', false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertSee('data-email-research-status="research_queued"', false);
    }

    public function test_email_thought_research_partial_shows_partial_status_on_index_and_stream(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Partial research email',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_partial',
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertSee('data-email-research-status="research_partial"', false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertSee('data-email-research-status="research_partial"', false);
    }

    public function test_email_thought_research_completed_with_research_thought_id_shows_status_and_link(): void
    {
        $user = User::factory()->create();
        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Research doc',
            'source' => 'research',
            'metadata' => ['type' => 'research'],
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Source email',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_completed',
                    'research_thought_id' => $research->id,
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertSee('data-email-research-status="research_completed"', false);
        $index->assertSee(route('idea.research.show', $research), false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertSee('data-email-research-status="research_completed"', false);
        $stream->assertSee(route('idea.research.show', $research), false);

        $detail = $this->actingAs($user)->get(route('thoughts.show', $emailThought));
        $detail->assertStatus(200);
        $detail->assertSee('data-email-research-status="research_completed"', false);
        $detail->assertSee(route('idea.research.show', $research), false);
    }

    public function test_stale_queued_status_with_valid_linked_research_shows_ready_status_and_link(): void
    {
        $user = User::factory()->create();
        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Research doc',
            'source' => 'research',
            'metadata' => ['type' => 'research'],
        ]);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Source email with stale queued status',
            'source' => 'email',
            'source_metadata' => [
                'research_thought_id' => $research->id,
                'newsletter_research' => [
                    'status' => 'research_queued',
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertSee('data-email-research-status="research_completed"', false);
        $index->assertDontSee('data-email-research-status="research_queued"', false);
        $index->assertSee(route('idea.research.show', $research), false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertSee('data-email-research-status="research_completed"', false);
        $stream->assertDontSee('data-email-research-status="research_queued"', false);
        $stream->assertSee(route('idea.research.show', $research), false);

        $detail = $this->actingAs($user)->get(route('thoughts.show', $thought));
        $detail->assertStatus(200);
        $detail->assertSee('data-email-research-status="research_completed"', false);
        $detail->assertDontSee('data-email-research-status="research_queued"', false);
        $detail->assertSee(route('idea.research.show', $research), false);
    }

    public function test_non_email_thought_does_not_render_email_research_status(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Web thought',
            'source' => 'web',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_completed',
                    'research_thought_id' => '00000000-0000-0000-0000-000000000001',
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertDontSee('data-email-research-status=', false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertDontSee('data-email-research-status=', false);
    }

    public function test_email_thought_research_skipped_with_reason_shows_reason_and_info_ui_on_index_and_stream(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Skipped newsletter email',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_skipped',
                    'reason' => 'Not enough meaningful content to research.',
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertSee('data-email-research-status="research_skipped"', false);
        $index->assertSee('Skipped: Not enough meaningful content to research.', false);
        $index->assertSee('Why research was skipped', false);
        $index->assertSee('aria-controls="email-research-skip-reason-'.$thought->id.'"', false);
        $index->assertSee('id="email-research-skip-reason-'.$thought->id.'"', false);
        $index->assertSee('data-email-research-skip-reason', false);
        $index->assertSee('data-email-research-skip-hover-bridge', false);
        $index->assertDontSee('role="tooltip"', false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertSee('data-email-research-status="research_skipped"', false);
        $stream->assertSee('Skipped: Not enough meaningful content to research.', false);
        $stream->assertSee('Why research was skipped', false);
        $stream->assertSee('aria-controls="email-research-skip-reason-'.$thought->id.'"', false);
        $stream->assertSee('id="email-research-skip-reason-'.$thought->id.'"', false);
        $stream->assertSee('data-email-research-skip-reason', false);
        $stream->assertSee('data-email-research-skip-hover-bridge', false);
        $stream->assertDontSee('role="tooltip"', false);
    }

    public function test_email_thought_detail_page_shows_skipped_reason_and_info_ui(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'content' => 'Email body',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_skipped',
                    'reason' => 'Not enough meaningful content to research.',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('thoughts.show', $thought));

        $response->assertStatus(200);
        $response->assertSee('data-email-research-status="research_skipped"', false);
        $response->assertSee('Skipped: Not enough meaningful content to research.', false);
        $response->assertSee('Why research was skipped', false);
        $response->assertSee('aria-controls="email-research-skip-reason-'.$thought->id.'"', false);
        $response->assertSee('id="email-research-skip-reason-'.$thought->id.'"', false);
        $response->assertSee('data-email-research-skip-reason', false);
        $response->assertSee('data-email-research-skip-hover-bridge', false);
        $response->assertDontSee('role="tooltip"', false);
    }

    public function test_skipped_status_without_reason_renders_only_badge_without_info_ui(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Skipped without reason',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_skipped',
                    'reason' => '   ',
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertSee('data-email-research-status="research_skipped"', false);
        $index->assertDontSee('Why research was skipped', false);
        $index->assertDontSee('Skipped:', false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertSee('data-email-research-status="research_skipped"', false);
        $stream->assertDontSee('Why research was skipped', false);
        $stream->assertDontSee('Skipped:', false);
    }

    public function test_non_skipped_status_does_not_render_skipped_reason_ui(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Queued email',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_queued',
                    'reason' => 'Not enough meaningful content to research.',
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertSee('data-email-research-status="research_queued"', false);
        $index->assertDontSee('Why research was skipped', false);
        $index->assertDontSee('Skipped:', false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertSee('data-email-research-status="research_queued"', false);
        $stream->assertDontSee('Why research was skipped', false);
        $stream->assertDontSee('Skipped:', false);
    }

    public function test_skipped_reason_is_escaped_in_rendered_output(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Escaping case',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_skipped',
                    'reason' => '<script>alert(1)</script>',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertStatus(200);
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('Skipped: <script>alert(1)</script>', false);
        $response->assertSee('Skipped: &lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_email_research_status_renders_in_index_and_stream_ajax_fragments(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Email ajax fragment',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_queued',
                ],
            ],
        ]);

        $index = $this->actingAs($user)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('idea.index'));
        $index->assertOk();
        $this->assertStringContainsString('data-email-research-status="research_queued"', $index->json('html'));

        $stream = $this->actingAs($user)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('idea.stream'));
        $stream->assertOk();
        $this->assertStringContainsString('data-email-research-status="research_queued"', $stream->json('html'));
    }

    public function test_demo_mode_obfuscates_newsletter_skip_reason_on_index_stream_and_detail(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();
        $secretReason = 'IDEATUB_SECRET_NEWSLETTER_SKIP_REASON_DEMO_PATH';
        $seed = 'feat-seed-newsletter-skip-reason-demo';
        $obfuscated = app(DemoObfuscationGenerator::class)->generate(
            $secretReason,
            'newsletter_research_skip_reason',
            $seed
        );
        $skippedLabel = 'Skipped: '.$obfuscated;

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Skipped newsletter email demo',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_skipped',
                    'reason' => $secretReason,
                ],
            ],
        ]);

        $demo = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => $seed,
        ])->actingAs($user);

        foreach ([
            $demo->get(route('idea.index')),
            $demo->get(route('idea.stream')),
            $demo->get(route('thoughts.show', $thought)),
        ] as $response) {
            $response->assertOk();
            $response->assertSee($skippedLabel, false);
            $response->assertDontSee($secretReason, false);
            $this->assertStringNotContainsString($secretReason, $response->getContent());
        }

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $normalIndex = $this->actingAs($user)->get(route('idea.index'));
        $normalIndex->assertOk();
        $normalIndex->assertSee('Skipped: '.$secretReason, false);

        $normalStream = $this->actingAs($user)->get(route('idea.stream'));
        $normalStream->assertOk();
        $normalStream->assertSee('Skipped: '.$secretReason, false);

        $normalDetail = $this->actingAs($user)->get(route('thoughts.show', $thought));
        $normalDetail->assertOk();
        $normalDetail->assertSee('Skipped: '.$secretReason, false);
    }

    public function test_demo_mode_obfuscates_newsletter_skip_reason_in_index_and_stream_ajax_fragments(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();
        $secretReason = 'IDEATUB_SECRET_NEWSLETTER_SKIP_AJAX_FRAGMENT';
        $seed = 'feat-seed-newsletter-skip-ajax';
        $obfuscated = app(DemoObfuscationGenerator::class)->generate(
            $secretReason,
            'newsletter_research_skip_reason',
            $seed
        );

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Email ajax skipped demo',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_skipped',
                    'reason' => $secretReason,
                ],
            ],
        ]);

        $demo = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => $seed,
        ])->actingAs($user);

        $index = $demo
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('idea.index'));
        $index->assertOk();
        $htmlIndex = $index->json('html');
        $this->assertStringContainsString('Skipped: '.$obfuscated, $htmlIndex);
        $this->assertStringNotContainsString($secretReason, $htmlIndex);

        $stream = $demo
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('idea.stream'));
        $stream->assertOk();
        $htmlStream = $stream->json('html');
        $this->assertStringContainsString('Skipped: '.$obfuscated, $htmlStream);
        $this->assertStringNotContainsString($secretReason, $htmlStream);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
    }
}
