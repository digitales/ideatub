<?php

namespace Tests\Unit\View\Presenters\Ideas;

use App\Models\Thought;
use App\Models\User;
use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use App\View\Presenters\Ideas\CompletedIdeaPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompletedIdeaPresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'America/New_York']);
    }

    #[Test]
    public function it_formats_logged_date_for_display_line(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-04-01',
                'completed_at' => '2026-03-24T15:30:00+00:00',
            ],
            'embedding' => null,
        ]);

        $row = CompletedIdeaPresenter::from($thought);

        $this->assertSame('April 1, 2025', $row->loggedFormatted());
    }

    #[Test]
    public function it_formats_parsed_completed_at_in_app_timezone(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-04-01',
                'completed_at' => '2026-03-24T15:30:00+00:00',
            ],
            'embedding' => null,
        ]);

        $row = CompletedIdeaPresenter::from($thought);

        $this->assertSame('March 24, 2026', $row->completedFormatted());
    }

    #[Test]
    public function it_uses_em_dash_when_completed_at_is_missing_or_unparseable(): void
    {
        $user = User::factory()->create();
        $legacy = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);
        $malformed = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-04-01',
                'completed_at' => '2026-13-40T10:00:00+00:00',
            ],
            'embedding' => null,
        ]);

        $this->assertSame('—', CompletedIdeaPresenter::from($legacy)->completedFormatted());
        $this->assertSame('—', CompletedIdeaPresenter::from($malformed)->completedFormatted());
    }

    #[Test]
    public function it_exposes_thought_for_links_and_content(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Done',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-05-01',
                'completed_at' => '2026-03-24T16:00:00+00:00',
            ],
            'embedding' => null,
        ]);

        $row = CompletedIdeaPresenter::from($thought);

        $this->assertSame($thought->id, $row->thought()->id);
        $this->assertSame('Done', $row->thought()->content);
    }

    #[Test]
    public function it_returns_limited_raw_display_excerpt_when_demo_mode_is_off(): void
    {
        config(['services.demo_mode.enabled' => true]);
        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $user = User::factory()->create();
        $body = 'COMPLETED_UNIT_EXCERPT_MARKER'.str_repeat('z', 250);
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => $body,
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-04-01',
                'completed_at' => '2026-03-24T15:30:00+00:00',
            ],
            'embedding' => null,
        ]);

        $row = CompletedIdeaPresenter::from($thought);

        $this->assertSame(Str::limit($body, 200), $row->displayExcerpt());
        $this->assertStringContainsString('COMPLETED_UNIT_EXCERPT_MARKER', $row->displayExcerpt());
    }

    #[Test]
    public function it_obfuscates_display_excerpt_in_demo_mode_while_preserving_formatted_date_labels(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'COMPLETED_UNIT_DEMO_EXCERPT_SECRET',
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'logged_date' => '2025-04-01',
                'completed_at' => '2026-03-24T15:30:00+00:00',
            ],
            'embedding' => null,
        ]);

        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'unit-seed-completed-presenter',
        ]);

        try {
            $row = CompletedIdeaPresenter::from($thought);

            $this->assertStringNotContainsString('COMPLETED_UNIT_DEMO_EXCERPT_SECRET', $row->displayExcerpt());
            $this->assertSame(
                app(DemoObfuscator::class)->obfuscate(
                    Str::limit('COMPLETED_UNIT_DEMO_EXCERPT_SECRET', 200),
                    'thought_content',
                ),
                $row->displayExcerpt(),
            );
            $this->assertSame('April 1, 2025', $row->loggedFormatted());
            $this->assertSame('March 24, 2026', $row->completedFormatted());
        } finally {
            session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        }
    }
}
