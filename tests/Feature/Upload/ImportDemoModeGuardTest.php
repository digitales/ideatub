<?php

namespace Tests\Feature\Upload;

use App\Models\User;
use App\Services\DemoMode;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ImportDemoModeGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_import_returns_403_in_demo_mode(): void
    {
        $m = Mockery::mock(DemoMode::class);
        $m->shouldReceive('enabled')->andReturn(true);
        $this->app->instance(DemoMode::class, $m);
        $this->app->instance(OpenRouterService::class, Mockery::mock(OpenRouterService::class, function ($o): void {
            $o->shouldIgnoreMissing();
        }));

        $file = UploadedFile::fake()->create('x.md', 5, 'text/plain');
        $r = $this->actingAs(User::factory()->create())
            ->post(route('imports.quick'), ['files' => [$file]]);

        $r->assertStatus(403);
    }

    public function test_batch_import_returns_403_in_demo_mode(): void
    {
        $m = Mockery::mock(DemoMode::class);
        $m->shouldReceive('enabled')->andReturn(true);
        $this->app->instance(DemoMode::class, $m);

        Storage::fake('local');
        $a = UploadedFile::fake()->create('a.md', 4, 'text/plain');

        $r = $this->actingAs(User::factory()->create())
            ->post(route('imports.batch'), [
                'project_title' => 'P',
                'dedupe_mode' => 'new',
                'relative_paths' => ['a.md'],
                'files' => [$a],
            ]);

        $r->assertStatus(403);
    }
}
