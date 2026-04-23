<?php

namespace Tests\Feature\Upload;

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PruneExpiredImportBatchesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_stale_import_batches(): void
    {
        $user = User::factory()->create();
        $old = ImportBatch::create([
            'user_id' => $user->id,
            'source' => 'upload_multi',
            'status' => 'completed',
            'file_count' => 0,
            'total_bytes' => 0,
            'staging_path' => "imports/{$user->id}/o",
        ]);
        $old->forceFill(['updated_at' => Carbon::now()->subDays(40)])->save();
        $fresh = ImportBatch::create([
            'user_id' => $user->id,
            'source' => 'upload_multi',
            'status' => 'processing',
            'file_count' => 0,
            'total_bytes' => 0,
            'staging_path' => "imports/{$user->id}/f",
        ]);

        $this->artisan('imports:prune-expired-batches', ['--days' => 30])->assertSuccessful();

        $this->assertNull(ImportBatch::find($old->id));
        $this->assertNotNull(ImportBatch::find($fresh->id));
    }
}
