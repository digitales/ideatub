<?php

namespace Tests\Feature\Upload;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageImportToolbarTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_includes_file_import_toolbar_when_feature_is_on(): void
    {
        $user = User::factory()->create();
        $r = $this->actingAs($user)->get(route('idea.index'));
        $r->assertOk();
        $r->assertSee('data-capture-import-toolbar', false);
    }
}
