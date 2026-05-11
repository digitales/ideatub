<?php

namespace Tests\Feature\Import;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\Import\FileImportService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MarkdownImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.0));
        $mock->shouldReceive('extractMetadata')->andReturn(['type' => 'note', 'tags' => []]);
        $this->app->instance(OpenRouterService::class, $mock);
    }

    public function test_imports_thought_type_with_correct_source(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test Project']);

        $service = app(FileImportService::class);
        $thought = $service->importMarkdownWithMetadata(
            content: '# My thought content',
            title: 'My Thought',
            type: 'thought',
            project: $project,
            user: $user,
            originalFilename: 'my-thought.md',
        );

        $this->assertInstanceOf(Thought::class, $thought);
        $this->assertEquals('upload', $thought->source);
        $this->assertEquals('My Thought', data_get($thought->metadata, 'title'));
        $this->assertTrue($project->thoughts()->whereKey($thought->id)->exists());
    }

    public function test_imports_meeting_type_with_correct_source(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test Project']);

        $service = app(FileImportService::class);
        $thought = $service->importMarkdownWithMetadata(
            content: '# Meeting notes for Q2 planning',
            title: 'Q2 Planning',
            type: 'meeting',
            project: $project,
            user: $user,
        );

        $this->assertEquals('meeting', $thought->source);
        $this->assertEquals('meeting', data_get($thought->source_metadata, 'doc_type'));
        $this->assertEquals('Q2 Planning', data_get($thought->metadata, 'title'));
    }

    public function test_imports_research_type_with_correct_source(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test Project']);

        $service = app(FileImportService::class);
        $thought = $service->importMarkdownWithMetadata(
            content: '# Research on competitor pricing',
            title: 'Competitor Pricing',
            type: 'research',
            project: $project,
            user: $user,
        );

        $this->assertEquals('research', $thought->source);
        $this->assertEquals('research', data_get($thought->source_metadata, 'doc_type'));
    }

    public function test_preserves_original_filename_in_source_metadata(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test Project']);

        $service = app(FileImportService::class);
        $thought = $service->importMarkdownWithMetadata(
            content: '# Content',
            title: 'Title',
            type: 'thought',
            project: $project,
            user: $user,
            originalFilename: 'notes-2026-05-11.md',
        );

        $this->assertEquals('notes-2026-05-11.md', data_get($thought->source_metadata, 'original_filename'));
    }
}
