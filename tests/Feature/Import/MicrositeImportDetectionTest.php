<?php

namespace Tests\Feature\Import;

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

class MicrositeImportDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldIgnoreMissing();
        $this->app->instance(OpenRouterService::class, $mock);
        Storage::fake('local');
        Bus::fake();
    }

    public function test_batch_with_two_numbered_markdown_files_sets_microsite_on_import_batch(): void
    {
        $user = User::factory()->create();
        $a = UploadedFile::fake()->create('00-a.md', 8, 'text/plain');
        $b = UploadedFile::fake()->create('01-b.md', 8, 'text/plain');

        $this->actingAs($user)->post(
            route('imports.batch'),
            [
                'project_title' => 'Site',
                'dedupe_mode' => 'new',
                'relative_paths' => ['f/00-a.md', 'f/01-b.md'],
                'files' => [$a, $b],
            ],
            ['Accept' => 'text/html'],
        );

        $batch = ImportBatch::query()->where('user_id', $user->id)->orderBy('created_at', 'desc')->first();
        $this->assertNotNull($batch);
        $this->assertSame('microsite', data_get($batch->options, 'import_kind'));

        Bus::assertBatched(function (PendingBatch $b): bool {
            return collect($b->jobs)->every(fn ($j) => $j instanceof ProcessImportFile);
        });
    }

    public function test_mixed_txt_and_numbered_markdown_is_not_microsite(): void
    {
        $user = User::factory()->create();
        $md = UploadedFile::fake()->create('00-a.md', 8, 'text/plain');
        $txt = UploadedFile::fake()->create('notes.txt', 4, 'text/plain');

        $this->actingAs($user)->post(
            route('imports.batch'),
            [
                'project_title' => 'Site',
                'dedupe_mode' => 'new',
                'relative_paths' => ['f/00-a.md', 'f/notes.txt'],
                'files' => [$md, $txt],
            ],
            ['Accept' => 'text/html'],
        );

        $batch = ImportBatch::query()->where('user_id', $user->id)->orderBy('created_at', 'desc')->first();
        $this->assertNotNull($batch);
        $this->assertNotSame('microsite', data_get($batch->options, 'import_kind'));
    }

    public function test_single_numbered_markdown_file_is_not_microsite(): void
    {
        $user = User::factory()->create();
        $a = UploadedFile::fake()->create('00-a.md', 8, 'text/plain');

        $this->actingAs($user)->post(
            route('imports.batch'),
            [
                'project_title' => 'One',
                'dedupe_mode' => 'new',
                'relative_paths' => ['f/00-a.md'],
                'files' => [$a],
            ],
            ['Accept' => 'text/html'],
        );

        $batch = ImportBatch::query()->where('user_id', $user->id)->orderBy('created_at', 'desc')->first();
        $this->assertNotNull($batch);
        $this->assertNotSame('microsite', data_get($batch->options, 'import_kind'));
    }

    public function test_duplicate_page_basenames_fails_validation(): void
    {
        $user = User::factory()->create();
        $a = UploadedFile::fake()->create('00-a.md', 8, 'text/plain');
        $b = UploadedFile::fake()->create('00-a.md', 6, 'text/plain');

        $response = $this->actingAs($user)->from(route('idea.index'))->post(
            route('imports.batch'),
            [
                'project_title' => 'Dup',
                'dedupe_mode' => 'new',
                'relative_paths' => ['a/00-a.md', 'b/00-a.md'],
                'files' => [$a, $b],
            ],
        );

        $response->assertSessionHasErrors('files');
        $this->assertNull(ImportBatch::query()->where('user_id', $user->id)->first());
    }
}
