<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateThoughtTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_tags_via_json(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'An idea',
            'metadata' => ['type' => 'idea', 'tags' => []],
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->patchJson(route('ideas.update-tags', $thought), [
            'tags' => ['plan:test', 'stream'],
        ]);

        $response->assertOk();
        $response->assertJson(['tags' => ['plan:test', 'stream']]);

        $thought->refresh();
        $this->assertSame(['plan:test', 'stream'], $thought->metadata['tags'] ?? null);
    }

    public function test_tags_are_normalized_and_deduplicated(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'An idea',
            'metadata' => ['type' => 'idea', 'tags' => []],
            'embedding' => null,
        ]);

        $this->actingAs($owner)->patchJson(route('ideas.update-tags', $thought), [
            'tags' => ['  MixedCase  ', 'mixedcase', 'mixedcase'],
        ]);

        $thought->refresh();
        $this->assertSame(['mixedcase'], $thought->metadata['tags'] ?? null);
    }

    public function test_validation_requires_tags_array(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'An idea',
            'metadata' => ['type' => 'idea'],
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->patchJson(route('ideas.update-tags', $thought), [
            'tags' => 'not-an-array',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('tags');
    }

    public function test_guest_cannot_update_tags(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'An idea',
            'metadata' => ['tags' => []],
            'embedding' => null,
        ]);

        $response = $this->patchJson(route('ideas.update-tags', $thought), [
            'tags' => ['plan:test'],
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_cannot_update_another_users_thought_tags(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'An idea',
            'metadata' => ['tags' => ['original']],
            'embedding' => null,
        ]);

        $response = $this->actingAs($other)->patchJson(route('ideas.update-tags', $thought), [
            'tags' => ['hacked'],
        ]);

        $response->assertForbidden();

        $thought->refresh();
        $this->assertSame(['original'], $thought->metadata['tags'] ?? null);
    }

    public function test_empty_tags_array_is_allowed(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'An idea',
            'metadata' => ['type' => 'idea', 'tags' => ['old']],
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->patchJson(route('ideas.update-tags', $thought), [
            'tags' => [],
        ]);

        $response->assertOk();
        $response->assertJson(['tags' => []]);

        $thought->refresh();
        $this->assertSame([], $thought->metadata['tags'] ?? null);
    }
}
