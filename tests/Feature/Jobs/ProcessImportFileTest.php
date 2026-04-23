<?php

namespace Tests\Feature\Jobs;

use App\Events\ImportFileProcessed;
use App\Jobs\ProcessImportFile;
use App\Models\ImportBatch;
use App\Models\ImportBatchFile;
use App\Models\User;
use App\Services\Import\FileImportService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProcessImportFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_processes_file_and_broadcasts_event(): void
    {
        Storage::fake('local');
        Event::fake([ImportFileProcessed::class]);

        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.0));
        $mock->shouldReceive('extractMetadata')->andReturn(['type' => 'note', 'tags' => []]);
        $this->app->instance(OpenRouterService::class, $mock);

        $user = User::factory()->create();
        $batch = ImportBatch::create([
            'user_id' => $user->id, 'source' => 'upload_multi', 'status' => 'processing',
            'file_count' => 1, 'total_bytes' => 5,
            'staging_path' => "imports/{$user->id}/b",
        ]);
        $row = ImportBatchFile::create([
            'import_batch_id' => $batch->id, 'relative_path' => 'a.md',
            'original_filename' => 'a.md', 'size_bytes' => 5, 'status' => 'pending',
        ]);
        Storage::disk('local')->put("{$batch->staging_path}/{$row->id}", 'hello');

        (new ProcessImportFile($row->id))->handle(app(FileImportService::class));

        Event::assertDispatched(ImportFileProcessed::class);
        $this->assertSame('done', $row->fresh()->status);
    }
}
