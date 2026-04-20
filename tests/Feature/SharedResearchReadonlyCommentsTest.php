<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedResearchReadonlyCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_shared_view_shows_existing_comments_and_form_when_allowed(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'content' => '# Doc',
            'metadata' => ['type' => 'research'],
        ]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => true,
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => null,
            'author_name' => 'Ada',
            'content' => 'public-reply',
            'format' => 'plain',
        ]);

        $response = $this->get(route('shared-research.show', $share->token));
        $response->assertOk();
        $response->assertSee('public-reply', false);
        $response->assertSee('Ada', false);
        $response->assertSee('name="author_name"', false);
    }

    public function test_shared_view_hides_form_when_disabled(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'metadata' => ['type' => 'research'],
        ]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => false,
        ]);

        $response = $this->get(route('shared-research.show', $share->token));
        $response->assertOk();
        $response->assertDontSee('name="author_name"', false);
    }
}
