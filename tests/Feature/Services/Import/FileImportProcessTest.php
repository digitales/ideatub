<?php

namespace Tests\Feature\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportBatchFile;
use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\Import\FileImportService;
use App\Services\Import\ImportStagingStore;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class FileImportProcessTest extends TestCase
{
    use RefreshDatabase;

    private function mockOpenRouter(): void
    {
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.0));
        $mock->shouldReceive('extractMetadata')->andReturn(['type' => 'note', 'tags' => []]);
        $this->app->instance(OpenRouterService::class, $mock);
    }

    private function makeBatchWithFile(User $user, string $content, string $relPath = 'notes.md'): ImportBatchFile
    {
        Storage::fake('local');
        $project = Project::create(['user_id' => $user->id, 'title' => 'P']);
        $batch = ImportBatch::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'source' => 'upload_folder',
            'status' => 'processing',
            'file_count' => 1,
            'total_bytes' => strlen($content),
            'staging_path' => "imports/{$user->id}/b",
        ]);
        $row = ImportBatchFile::create([
            'import_batch_id' => $batch->id,
            'relative_path' => $relPath,
            'original_filename' => basename($relPath),
            'size_bytes' => strlen($content),
            'status' => 'pending',
        ]);
        Storage::disk('local')->put("{$batch->staging_path}/{$row->id}", $content);

        return $row;
    }

    public function test_happy_path_creates_thought_and_deletes_staging(): void
    {
        $this->mockOpenRouter();
        $user = User::factory()->create();
        $row = $this->makeBatchWithFile($user, "# hello\n\nworld");

        app(FileImportService::class)->process($row);
        $row->refresh();

        $this->assertSame('done', $row->status);
        $this->assertNotNull($row->thought_id);
        $thought = Thought::find($row->thought_id);
        $this->assertSame('upload', $thought->source);
        $this->assertSame('upload', $thought->source_metadata['provenance']);
        $this->assertTrue($thought->source_metadata['untrusted_origin']);
        $this->assertSame('notes.md', $thought->source_metadata['original_filename']);
        $this->assertTrue(app(ImportStagingStore::class)->readStaged($row->batch, $row) === '');
    }

    public function test_links_to_project_and_tags_subfolder(): void
    {
        $this->mockOpenRouter();
        $user = User::factory()->create();
        $row = $this->makeBatchWithFile($user, 'body', 'meetings/2026-q2/standup.md');

        app(FileImportService::class)->process($row);
        $row->refresh();

        $thought = Thought::find($row->thought_id);
        $this->assertContains('folder:meetings', $thought->metadata['tags']);
        $this->assertContains('folder:2026-q2', $thought->metadata['tags']);
        $this->assertTrue($row->batch->project->thoughts()->where('thoughts.id', $thought->id)->exists());
    }

    public function test_dedupe_links_existing_thought_instead_of_creating_new(): void
    {
        $this->mockOpenRouter();
        $user = User::factory()->create();
        $existing = Thought::create([
            'user_id' => $user->id,
            'content' => "# hello\n\nworld",
            'source' => 'web',
        ]);
        $row = $this->makeBatchWithFile($user, "# hello\n\nworld");

        app(FileImportService::class)->process($row);
        $row->refresh();

        $this->assertSame('skipped_duplicate', $row->status);
        $this->assertSame($existing->id, $row->thought_id);
        $this->assertSame(1, $row->batch->project->thoughts()->count());
    }

    public function test_rejected_file_marks_failed_without_creating_thought(): void
    {
        $this->mockOpenRouter();
        $user = User::factory()->create();
        $row = $this->makeBatchWithFile($user, "\x00\x00binary");

        app(FileImportService::class)->process($row);
        $row->refresh();

        $this->assertSame('failed', $row->status);
        $this->assertSame('binary_detected', $row->error_code);
        $this->assertNull($row->thought_id);
    }
}
