<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateThoughtContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_content_via_json(): void
    {
        $owner = User::factory()->create();
        $sourceMetadata = ['capture' => 'email', 'ref' => 'abc-123'];
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Typo tehre',
            'metadata' => ['tags' => ['keep-me'], 'type' => 'idea', 'completed' => false],
            'source_metadata' => $sourceMetadata,
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->patchJson(route('ideas.update-content', $thought), [
            'content' => 'Typo there',
        ]);

        $response->assertOk();
        $response->assertJson(['content' => 'Typo there']);

        $thought->refresh();
        $this->assertSame('Typo there', $thought->content);
        $this->assertSame(['keep-me'], $thought->metadata['tags'] ?? null);
        $this->assertSame('idea', $thought->metadata['type'] ?? null);
        $this->assertFalse($thought->metadata['completed'] ?? true);
        $this->assertSame($sourceMetadata, $thought->source_metadata);
    }

    public function test_validation_rejects_empty_or_whitespace_only_content(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Original content',
            'metadata' => ['tags' => ['keep-me']],
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->patchJson(route('ideas.update-content', $thought), [
            'content' => '   ',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('content');

        $thought->refresh();
        $this->assertSame('Original content', $thought->content);
        $this->assertSame(['keep-me'], $thought->metadata['tags'] ?? null);
    }

    public function test_guest_cannot_update_content(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Original content',
            'embedding' => null,
        ]);

        $response = $this->patchJson(route('ideas.update-content', $thought), [
            'content' => 'Changed content',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_cannot_update_another_users_thought_content(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Owner content',
            'metadata' => ['tags' => ['original']],
            'embedding' => null,
        ]);

        $response = $this->actingAs($other)->patchJson(route('ideas.update-content', $thought), [
            'content' => 'Hacked content',
        ]);

        $response->assertForbidden();

        $thought->refresh();
        $this->assertSame('Owner content', $thought->content);
        $this->assertSame(['original'], $thought->metadata['tags'] ?? null);
    }

    public function test_json_response_includes_content_html_after_update(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Before',
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->patchJson(route('ideas.update-content', $thought), [
            'content' => 'Hello **world**',
        ]);

        $response->assertOk();
        $response->assertJsonPath('content', 'Hello **world**');
        $html = $response->json('content_html');
        $this->assertIsString($html);
        $this->assertStringContainsString('<p', $html);
        $this->assertStringContainsString('world', $html);
    }
}
