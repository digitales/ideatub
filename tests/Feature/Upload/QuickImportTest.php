<?php

namespace Tests\Feature\Upload;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class QuickImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_import_creates_thought_with_upload_provenance(): void
    {
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.0));
        $mock->shouldReceive('extractMetadata')->andReturn(['type' => 'note', 'tags' => []]);
        $this->app->instance(OpenRouterService::class, $mock);

        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('hi.md', 8, 'text/plain');

        $response = $this->actingAs($user)->from(route('idea.index'))->post(
            route('imports.quick'),
            ['files' => [$file]]
        );

        $response->assertRedirect(route('idea.index'));
        $response->assertSessionHas('success');

        $t = Thought::query()->where('user_id', $user->id)->orderBy('id', 'desc')->first();
        $this->assertNotNull($t);
        $this->assertSame('upload', $t->source);
        $this->assertSame('upload', data_get($t->source_metadata, 'provenance'));
        $this->assertNotEmpty($t->content);
    }
}
