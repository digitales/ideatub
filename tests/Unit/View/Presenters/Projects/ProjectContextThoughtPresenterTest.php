<?php

namespace Tests\Unit\View\Presenters\Projects;

use App\Models\Thought;
use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use App\View\Presenters\Projects\ProjectContextThoughtPresenter;
use Tests\TestCase;

class ProjectContextThoughtPresenterTest extends TestCase
{
    public function test_returns_real_markdown_when_demo_mode_is_off(): void
    {
        config(['services.demo_mode.enabled' => true]);
        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $thought = new Thought([
            'content' => "# Briefing\n\nNorth star context body.",
        ]);

        $presenter = ProjectContextThoughtPresenter::fromThought($thought);

        $this->assertSame("# Briefing\n\nNorth star context body.", $presenter->markdown());
        $this->assertFalse($presenter->isMicrositeLayout());
    }

    public function test_obfuscates_markdown_in_demo_mode(): void
    {
        config(['services.demo_mode.enabled' => true]);

        $thought = new Thought([
            'content' => 'PROJECT_CONTEXT_DEMO_SECRET_BODY',
        ]);

        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'unit-seed-project-context-presenter',
        ]);

        try {
            $presenter = ProjectContextThoughtPresenter::fromThought($thought);

            $this->assertStringNotContainsString('PROJECT_CONTEXT_DEMO_SECRET_BODY', $presenter->markdown());
            $this->assertSame(
                app(DemoObfuscator::class)->obfuscate('PROJECT_CONTEXT_DEMO_SECRET_BODY', 'thought_content'),
                $presenter->markdown(),
            );
        } finally {
            session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        }
    }

    public function test_obfuscates_microsite_display_label_in_demo_mode(): void
    {
        config(['services.demo_mode.enabled' => true]);

        $thought = new Thought([
            'content' => '# Recommendations',
            'source_metadata' => ['document_layout' => 'microsite'],
        ]);

        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'unit-seed-project-context-microsite',
        ]);

        try {
            $presenter = ProjectContextThoughtPresenter::fromThought($thought);

            $this->assertTrue($presenter->isMicrositeLayout());
            $this->assertStringNotContainsString('Recommendations', $presenter->displayLabel());
        } finally {
            session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        }
    }
}
