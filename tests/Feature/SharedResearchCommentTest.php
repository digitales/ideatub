<?php

namespace Tests\Feature;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedResearchCommentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guest_can_post_comment_when_share_allows(): void
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
            'allow_comments' => true,
        ]);

        $response = $this->post(route('shared-research.comment', $share->token), [
            'commentable_id' => $root->id,
            'author_name' => 'Jane',
            'content' => 'Nice research',
            'website_url' => '',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('comments', [
            'commentable_id' => $root->id,
            'author_user_id' => null,
            'author_name' => 'Jane',
            'format' => 'plain',
        ]);
    }

    public function test_guest_cannot_post_when_allow_comments_false(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => false,
        ]);

        $response = $this->post(route('shared-research.comment', $share->token), [
            'commentable_id' => $root->id,
            'author_name' => 'Jane',
            'content' => 'hi',
            'website_url' => '',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_honeypot_drops_submission_silently(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => true,
        ]);

        $response = $this->post(route('shared-research.comment', $share->token), [
            'commentable_id' => $root->id,
            'author_name' => 'Bot',
            'content' => 'spam',
            'website_url' => 'http://evil.example',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_commentable_must_belong_to_share(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);
        $otherRoot = Thought::factory()->create(['user_id' => $owner->id]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => true,
        ]);

        $response = $this->post(route('shared-research.comment', $share->token), [
            'commentable_id' => $otherRoot->id,
            'author_name' => 'Jane',
            'content' => 'hi',
            'website_url' => '',
        ]);

        $response->assertStatus(422);
    }

    public function test_content_exceeding_2000_chars_returns_422(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => true,
        ]);

        $response = $this->post(route('shared-research.comment', $share->token), [
            'commentable_id' => $root->id,
            'author_name' => 'Jane',
            'content' => str_repeat('a', 2001),
            'website_url' => '',
        ]);

        $response->assertStatus(422);
    }
}
