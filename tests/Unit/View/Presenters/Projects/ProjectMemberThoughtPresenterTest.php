<?php

namespace Tests\Unit\View\Presenters\Projects;

use App\Models\Thought;
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
}
