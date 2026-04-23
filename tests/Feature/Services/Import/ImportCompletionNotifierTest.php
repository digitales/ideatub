<?php

namespace Tests\Feature\Services\Import;

use App\Mail\ImportCompletedMail;
use App\Models\ImportBatch;
use App\Models\ImportBatchFile;
use App\Models\InboxItem;
use App\Models\Project;
use App\Models\User;
use App\Services\Import\ImportCompletionNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ImportCompletionNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_inbox_item_and_sends_mail_with_project_link(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'q2-notes']);
        $batch = $this->makeCompletedBatch($user, $project);

        app(ImportCompletionNotifier::class)->notify($batch);

        $this->assertDatabaseHas('inbox_items', [
            'user_id' => $user->id,
            'generator_type' => 'import_completed',
            'dedupe_key' => 'import:'.$batch->id,
        ]);
        Mail::assertQueued(ImportCompletedMail::class, fn ($m) => $m->hasTo($user->email));
        $this->assertNotNull($batch->fresh()->completion_notified_at);
    }

    public function test_is_idempotent(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'q2']);
        $batch = $this->makeCompletedBatch($user, $project);

        $notifier = app(ImportCompletionNotifier::class);
        $notifier->notify($batch);
        $notifier->notify($batch);

        $this->assertSame(1, InboxItem::query()->where('dedupe_key', 'import:'.$batch->id)->count());
        Mail::assertQueuedCount(1);
    }

    private function makeCompletedBatch(User $user, Project $project): ImportBatch
    {
        $batch = ImportBatch::create([
            'user_id' => $user->id, 'project_id' => $project->id,
            'root_folder_name' => 'q2-notes',
            'source' => 'upload_folder', 'status' => 'completed',
            'file_count' => 3, 'total_bytes' => 100,
            'processed_count' => 3, 'failed_count' => 0, 'skipped_count' => 0,
            'staging_path' => "imports/{$user->id}/b",
        ]);
        ImportBatchFile::create([
            'import_batch_id' => $batch->id, 'relative_path' => 'a.md',
            'original_filename' => 'a.md', 'size_bytes' => 10, 'status' => 'done',
        ]);

        return $batch;
    }
}
