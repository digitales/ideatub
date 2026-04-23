<?php

namespace Tests\Feature\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportBatchFile;
use App\Models\User;
use App\Services\Import\ImportStagingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportStagingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_file_under_batch_uuid_name(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $batch = ImportBatch::create([
            'user_id' => $user->id,
            'source' => 'upload_folder',
            'status' => 'pending',
            'file_count' => 1,
            'total_bytes' => 0,
            'staging_path' => "imports/{$user->id}/batch1",
        ]);
        $row = ImportBatchFile::create([
            'import_batch_id' => $batch->id,
            'relative_path' => 'deep/nested/notes.md',
            'original_filename' => 'notes.md',
            'size_bytes' => 5,
            'status' => 'pending',
        ]);

        $upload = UploadedFile::fake()->createWithContent('notes.md', 'hello');
        $store = app(ImportStagingStore::class);
        $store->store($upload, $batch, $row);

        Storage::disk('local')->assertExists("imports/{$user->id}/batch1/{$row->id}");
    }

    public function test_it_reads_and_deletes_staged_bytes(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $batch = ImportBatch::create([
            'user_id' => $user->id, 'source' => 'upload_folder', 'status' => 'pending',
            'file_count' => 1, 'total_bytes' => 0,
            'staging_path' => "imports/{$user->id}/b",
        ]);
        $row = ImportBatchFile::create([
            'import_batch_id' => $batch->id, 'relative_path' => 'x.txt',
            'original_filename' => 'x.txt', 'size_bytes' => 3, 'status' => 'pending',
        ]);
        $store = app(ImportStagingStore::class);
        $store->store(UploadedFile::fake()->createWithContent('x.txt', 'abc'), $batch, $row);

        $this->assertSame('abc', $store->readStaged($batch, $row));

        $store->deleteStaged($batch, $row);
        Storage::disk('local')->assertMissing("imports/{$user->id}/b/{$row->id}");
    }
}
