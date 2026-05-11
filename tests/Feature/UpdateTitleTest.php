<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_title_stores_title_in_metadata(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $response = $this->actingAs($user)
            ->patchJson(route('ideas.update-title', $thought), [
                'title' => 'My Research Title',
            ]);

        $response->assertOk();
        $response->assertJson(['title' => 'My Research Title']);
        $this->assertSame('My Research Title', $thought->fresh()->metadata['title']);
    }

    public function test_update_title_rejects_string_over_255_chars(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $response = $this->actingAs($user)
            ->patchJson(route('ideas.update-title', $thought), [
                'title' => str_repeat('x', 256),
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('title');
    }

    public function test_update_title_allows_nullable_to_clear_title(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => [], 'title' => 'Old Title'],
        ]);

        $response = $this->actingAs($user)
            ->patchJson(route('ideas.update-title', $thought), [
                'title' => null,
            ]);

        $response->assertOk();
        $response->assertJson(['title' => null]);
        $this->assertNull($thought->fresh()->metadata['title']);
    }

    public function test_update_title_requires_authorization(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $response = $this->actingAs($other)
            ->patchJson(route('ideas.update-title', $thought), [
                'title' => 'Hacked',
            ]);

        $response->assertForbidden();
    }
}
