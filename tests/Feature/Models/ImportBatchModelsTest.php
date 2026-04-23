<?php

namespace Tests\Feature\Models;

use App\Models\ImportBatch;
use App\Models\ImportBatchFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportBatchModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_batch_has_files_and_user(): void
    {
        $user = User::factory()->create();

        $batch = ImportBatch::create([
            'user_id' => $user->id,
            'source' => 'upload_folder',
            'status' => 'pending',
            'file_count' => 2,
            'total_bytes' => 1234,
            'staging_path' => 'imports/'.$user->id.'/fake',
        ]);

        ImportBatchFile::create([
            'import_batch_id' => $batch->id,
            'relative_path' => 'foo.md',
            'original_filename' => 'foo.md',
            'size_bytes' => 100,
            'status' => 'pending',
        ]);

        $this->assertSame(1, $batch->files()->count());
        $this->assertSame($user->id, $batch->user->id);
    }

    public function test_import_batch_has_array_casts(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::create([
            'user_id' => $user->id,
            'source' => 'upload_folder',
            'status' => 'pending',
            'file_count' => 0,
            'total_bytes' => 0,
            'staging_path' => 'x',
            'options' => ['x' => 1],
        ]);

        $this->assertSame(['x' => 1], $batch->fresh()->options);
    }
}
