<?php

namespace Tests\Feature\Upload;

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_import_batch_status_page(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::create([
            'user_id' => $user->id,
            'source' => 'upload_multi',
            'status' => 'processing',
            'file_count' => 0,
            'total_bytes' => 0,
            'staging_path' => "imports/{$user->id}/b",
        ]);

        $r = $this->actingAs($user)->get(route('imports.show', $batch));
        $r->assertOk();
    }

    public function test_stranger_cannot_view_another_users_batch(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $batch = ImportBatch::create([
            'user_id' => $a->id,
            'source' => 'upload_multi',
            'status' => 'processing',
            'file_count' => 0,
            'total_bytes' => 0,
            'staging_path' => "imports/{$a->id}/b",
        ]);

        $this->actingAs($b)->get(route('imports.show', $batch))->assertForbidden();
    }

    public function test_status_json_includes_batch_and_file_payload(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::create([
            'user_id' => $user->id,
            'source' => 'upload_multi',
            'status' => 'processing',
            'file_count' => 1,
            'total_bytes' => 1,
            'staging_path' => "imports/{$user->id}/b",
        ]);

        $j = $this->actingAs($user)->getJson(route('imports.status', $batch));
        $j->assertOk();
        $j->assertJsonPath('batch.id', (string) $batch->id);
        $j->assertJsonStructure(['batch', 'files']);
    }
}
