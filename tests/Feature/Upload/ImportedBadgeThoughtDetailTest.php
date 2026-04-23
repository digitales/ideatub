<?php

namespace Tests\Feature\Upload;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportedBadgeThoughtDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_thought_show_shows_imported_badge_for_upload_provenance(): void
    {
        $user = User::factory()->create();
        $t = Thought::create([
            'user_id' => $user->id,
            'content' => 'Hello',
            'source' => 'upload',
            'source_metadata' => [
                'provenance' => 'upload',
                'untrusted_origin' => true,
            ],
        ]);

        $r = $this->actingAs($user)->get(route('thoughts.show', $t));
        $r->assertOk();
        $r->assertSee('data-imported-badge', false);
    }
}
