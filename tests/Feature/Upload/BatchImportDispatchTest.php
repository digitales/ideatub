<?php

namespace Tests\Feature\Upload;

use App\Jobs\ProcessImportFile;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class BatchImportDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_import_persists_rows_and_dispatches_job_batch(): void
    {
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldIgnoreMissing();
        $this->app->instance(OpenRouterService::class, $mock);

        Storage::fake('local');
        Bus::fake();

        $user = User::factory()->create();
        $a = UploadedFile::fake()->create('a.md', 6, 'text/plain');
        $b = UploadedFile::fake()->create('b.md', 6, 'text/plain');

        $response = $this->actingAs($user)->post(
            route('imports.batch'),
            [
                'project_title' => 'Inbox',
                'dedupe_mode' => 'new',
                'relative_paths' => ['f/a.md', 'f/b.md'],
                'files' => [$a, $b],
            ],
            [
                'Accept' => 'text/html',
            ],
        );

        $batch = ImportBatch::query()->where('user_id', $user->id)->orderBy('created_at', 'desc')->first();
        $this->assertNotNull($batch);
        $response->assertRedirect(route('imports.show', $batch));
        $this->assertSame(2, $batch->files()->count());
        $this->assertNotEmpty($batch->laravel_batch_id);

        Bus::assertBatched(function (PendingBatch $b): bool {
            return collect($b->jobs)->every(fn ($j) => $j instanceof ProcessImportFile);
        });
    }
}
