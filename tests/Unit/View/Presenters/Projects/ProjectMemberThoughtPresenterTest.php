<?php

namespace Tests\Unit\View\Presenters\Projects;

use App\Models\Thought;
use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use App\View\Presenters\Projects\ProjectMemberThoughtPresenter;
use Tests\TestCase;

class ProjectMemberThoughtPresenterTest extends TestCase
{
    public function test_extracts_heading_title_and_excerpt_from_markdown_content(): void
    {
        $thought = new Thought([
            'content' => "# QA and testing\n\nRun Pest before every deploy.",
            'metadata' => ['type' => 'plan'],
        ]);
        $thought->updated_at = now();

        $presenter = ProjectMemberThoughtPresenter::fromThought($thought);

        $this->assertSame('QA and testing', $presenter->title());
        $this->assertSame('Run Pest before every deploy.', $presenter->excerpt());
        $this->assertSame('Plan', $presenter->typeLabel());
    }

    public function test_labels_microsite_thoughts_as_research(): void
    {
        $thought = new Thought([
            'content' => '# Recommendations',
            'source_metadata' => ['document_layout' => 'microsite'],
        ]);
        $thought->updated_at = now();

        $presenter = ProjectMemberThoughtPresenter::fromThought($thought);

        $this->assertSame('Recommendations', $presenter->title());
        $this->assertSame('Research', $presenter->typeLabel());
        $this->assertNull($presenter->excerpt());
    }

    public function test_returns_derived_title_and_excerpt_when_demo_mode_is_off(): void
    {
        config(['services.demo_mode.enabled' => true]);
        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $thought = new Thought([
            'content' => "# QA and testing\n\nRun Pest before every deploy.",
            'metadata' => ['type' => 'plan'],
        ]);
        $thought->updated_at = now();

        $presenter = ProjectMemberThoughtPresenter::fromThought($thought);

        $this->assertSame('QA and testing', $presenter->title());
        $this->assertSame('Run Pest before every deploy.', $presenter->excerpt());
    }

    public function test_obfuscates_title_and_excerpt_in_demo_mode(): void
    {
        config(['services.demo_mode.enabled' => true]);
        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'unit-seed-project-member-presenter',
        ]);

        try {
            $thought = new Thought([
                'content' => "# MEMBER_DEMO_TITLE_SECRET\n\nMEMBER_DEMO_EXCERPT_SECRET",
                'metadata' => ['type' => 'plan'],
            ]);
            $thought->updated_at = now();

            $presenter = ProjectMemberThoughtPresenter::fromThought($thought);

            $this->assertStringNotContainsString('MEMBER_DEMO_TITLE_SECRET', $presenter->title());
            $this->assertStringNotContainsString('MEMBER_DEMO_EXCERPT_SECRET', $presenter->excerpt() ?? '');
            $this->assertSame(
                app(DemoObfuscator::class)->obfuscate('MEMBER_DEMO_TITLE_SECRET', 'thought_content'),
                $presenter->title(),
            );
            $this->assertSame(
                app(DemoObfuscator::class)->obfuscate('MEMBER DEMO EXCERPT SECRET', 'thought_content'),
                $presenter->excerpt(),
            );
        } finally {
            session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        }
    }
}
