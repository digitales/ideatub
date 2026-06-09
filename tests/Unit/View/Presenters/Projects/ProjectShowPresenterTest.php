<?php

namespace Tests\Unit\View\Presenters\Projects;

use App\Models\Project;
use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use App\View\Presenters\Projects\ProjectShowPresenter;
use Tests\TestCase;

class ProjectShowPresenterTest extends TestCase
{
    public function test_returns_real_title_and_description_when_demo_mode_is_off(): void
    {
        config(['services.demo_mode.enabled' => true]);
        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $project = new Project([
            'title' => 'Acme redesign',
            'description' => 'Scope and milestones for Q3.',
        ]);

        $presenter = ProjectShowPresenter::fromProject($project);

        $this->assertSame('Acme redesign', $presenter->pageTitle());
        $this->assertSame('Scope and milestones for Q3.', $presenter->descriptionMarkdown());
    }

    public function test_obfuscates_title_and_description_in_demo_mode(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $project = new Project([
            'title' => 'PROJECT_SHOW_DEMO_TITLE_SECRET',
            'description' => 'PROJECT_SHOW_DEMO_DESC_SECRET',
        ]);

        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'unit-seed-project-show-presenter',
        ]);

        try {
            $presenter = ProjectShowPresenter::fromProject($project);

            $this->assertStringNotContainsString('PROJECT_SHOW_DEMO_TITLE_SECRET', $presenter->pageTitle());
            $this->assertStringNotContainsString('PROJECT_SHOW_DEMO_DESC_SECRET', $presenter->descriptionMarkdown());
            $this->assertSame(
                app(DemoObfuscator::class)->obfuscate('PROJECT_SHOW_DEMO_TITLE_SECRET', 'project_title'),
                $presenter->pageTitle(),
            );
            $this->assertSame(
                app(DemoObfuscator::class)->obfuscate('PROJECT_SHOW_DEMO_DESC_SECRET', 'project_description'),
                $presenter->descriptionMarkdown(),
            );
        } finally {
            session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        }
    }

    public function test_empty_description_returns_null(): void
    {
        $project = new Project([
            'title' => 'Title only',
            'description' => null,
        ]);

        $presenter = ProjectShowPresenter::fromProject($project);

        $this->assertSame('Title only', $presenter->pageTitle());
        $this->assertNull($presenter->descriptionMarkdown());
    }
}
