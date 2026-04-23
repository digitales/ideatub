<?php

namespace Tests\Feature\Policies;

use App\Models\ImportBatch;
use App\Models\User;
use App\Policies\ImportPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_cancel_retry_and_delete(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::create([
            'user_id' => $user->id,
            'source' => 'upload_folder',
            'status' => 'pending',
            'file_count' => 0,
            'total_bytes' => 0,
            'staging_path' => 'imports/x',
        ]);

        $policy = new ImportPolicy;
        $this->assertTrue($policy->view($user, $batch));
        $this->assertTrue($policy->cancel($user, $batch));
        $this->assertTrue($policy->retryFailed($user, $batch));
        $this->assertTrue($policy->deleteThoughts($user, $batch));
    }

    public function test_non_owner_cannot(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $batch = ImportBatch::create([
            'user_id' => $owner->id,
            'source' => 'upload_folder',
            'status' => 'pending',
            'file_count' => 0,
            'total_bytes' => 0,
            'staging_path' => 'imports/x',
        ]);

        $policy = new ImportPolicy;
        $this->assertFalse($policy->view($other, $batch));
    }
}
