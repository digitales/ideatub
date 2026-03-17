<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_guest_gets_401(): void
    {
        $response = $this->getJson(route('ideas.drafts.index'));
        $response->assertStatus(401);
    }

    public function test_index_authenticated_with_no_drafts_returns_200_and_empty_array(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson(route('ideas.drafts.index'));
        $response->assertOk();
        $response->assertExactJson([]);
    }

    public function test_index_authenticated_with_drafts_returns_200_and_array_with_id_content_preview_updated_at(): void
    {
        $user = User::factory()->create();
        $draft = Draft::create([
            'user_id' => $user->id,
            'content' => 'Hello world',
            'no_chunking' => false,
        ]);
        $response = $this->actingAs($user)->getJson(route('ideas.drafts.index'));
        $response->assertOk();
        $json = $response->json();
        $this->assertCount(1, $json);
        $this->assertSame($draft->id, $json[0]['id']);
        $this->assertArrayHasKey('content_preview', $json[0]);
        $this->assertArrayHasKey('updated_at', $json[0]);
        if (isset($json[0]['updated_at_human'])) {
            $this->assertNotEmpty($json[0]['updated_at_human']);
        }
    }

    public function test_index_returns_max_10_drafts(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 12; $i++) {
            Draft::create([
                'user_id' => $user->id,
                'content' => "Draft {$i}",
                'no_chunking' => false,
            ]);
        }
        $response = $this->actingAs($user)->getJson(route('ideas.drafts.index'));
        $response->assertOk();
        $this->assertCount(10, $response->json());
    }

    public function test_store_guest_gets_401(): void
    {
        $response = $this->postJson(route('ideas.drafts.store'), ['content' => 'x']);
        $response->assertStatus(401);
    }

    public function test_store_valid_body_creates_draft_returns_201_and_draft_payload(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson(route('ideas.drafts.store'), [
            'content' => 'My draft',
            'no_chunking' => true,
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('content', 'My draft');
        $response->assertJsonPath('no_chunking', true);
        $this->assertDatabaseHas('drafts', ['user_id' => $user->id, 'content' => 'My draft']);
    }

    public function test_store_cap_creates_11_drafts_only_10_exist_and_list_returns_10(): void
    {
        $user = User::factory()->create();
        $lastResponse = null;
        for ($i = 0; $i < 11; $i++) {
            $lastResponse = $this->actingAs($user)->postJson(route('ideas.drafts.store'), [
                'content' => "Draft {$i}",
                'no_chunking' => false,
            ]);
        }
        $this->assertNotNull($lastResponse);
        $lastResponse->assertStatus(201);
        $this->assertDatabaseCount('drafts', 10);
        $response = $this->actingAs($user)->getJson(route('ideas.drafts.index'));
        $response->assertOk();
        $this->assertCount(10, $response->json());
    }

    public function test_show_guest_gets_401(): void
    {
        $user = User::factory()->create();
        $draft = Draft::create(['user_id' => $user->id, 'content' => 'x', 'no_chunking' => false]);
        $response = $this->getJson(route('ideas.drafts.show', $draft));
        $response->assertStatus(401);
    }

    public function test_show_owner_gets_200_and_full_draft(): void
    {
        $user = User::factory()->create();
        $draft = Draft::create([
            'user_id' => $user->id,
            'content' => 'Full content here',
            'no_chunking' => true,
        ]);
        $response = $this->actingAs($user)->getJson(route('ideas.drafts.show', $draft));
        $response->assertOk();
        $response->assertJsonPath('id', $draft->id);
        $response->assertJsonPath('content', 'Full content here');
        $response->assertJsonPath('no_chunking', true);
        $response->assertJsonStructure(['id', 'content', 'no_chunking', 'updated_at']);
    }

    public function test_show_other_user_gets_404(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $draft = Draft::create(['user_id' => $owner->id, 'content' => 'x', 'no_chunking' => false]);
        $response = $this->actingAs($other)->getJson(route('ideas.drafts.show', $draft));
        $response->assertStatus(404);
    }

    public function test_show_invalid_uuid_gets_404(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson(route('ideas.drafts.show', ['draft' => '00000000-0000-0000-0000-000000000000']));
        $response->assertStatus(404);
    }

    public function test_update_guest_gets_401(): void
    {
        $user = User::factory()->create();
        $draft = Draft::create(['user_id' => $user->id, 'content' => 'x', 'no_chunking' => false]);
        $response = $this->patchJson(route('ideas.drafts.update', $draft), ['content' => 'y']);
        $response->assertStatus(401);
    }

    public function test_update_owner_succeeds_returns_200_and_updated_draft(): void
    {
        $user = User::factory()->create();
        $draft = Draft::create(['user_id' => $user->id, 'content' => 'old', 'no_chunking' => false]);
        $response = $this->actingAs($user)->patchJson(route('ideas.drafts.update', $draft), [
            'content' => 'new',
            'no_chunking' => true,
        ]);
        $response->assertOk();
        $response->assertJsonPath('content', 'new');
        $response->assertJsonPath('no_chunking', true);
        $draft->refresh();
        $this->assertSame('new', $draft->content);
        $this->assertTrue($draft->no_chunking);
    }

    public function test_update_other_user_gets_404(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $draft = Draft::create(['user_id' => $owner->id, 'content' => 'x', 'no_chunking' => false]);
        $response = $this->actingAs($other)->patchJson(route('ideas.drafts.update', $draft), [
            'content' => 'hacked',
            'no_chunking' => false,
        ]);
        $response->assertStatus(404);
        $draft->refresh();
        $this->assertSame('x', $draft->content);
    }

    public function test_destroy_guest_gets_401(): void
    {
        $user = User::factory()->create();
        $draft = Draft::create(['user_id' => $user->id, 'content' => 'x', 'no_chunking' => false]);
        $response = $this->deleteJson(route('ideas.drafts.destroy', $draft));
        $response->assertStatus(401);
        $this->assertDatabaseHas('drafts', ['id' => $draft->id]);
    }

    public function test_destroy_owner_returns_204_and_removes_draft(): void
    {
        $user = User::factory()->create();
        $draft = Draft::create(['user_id' => $user->id, 'content' => 'x', 'no_chunking' => false]);
        $response = $this->actingAs($user)->deleteJson(route('ideas.drafts.destroy', $draft));
        $response->assertStatus(204);
        $this->assertDatabaseMissing('drafts', ['id' => $draft->id]);
    }

    public function test_destroy_other_user_gets_404(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $draft = Draft::create(['user_id' => $owner->id, 'content' => 'x', 'no_chunking' => false]);
        $response = $this->actingAs($other)->deleteJson(route('ideas.drafts.destroy', $draft));
        $response->assertStatus(404);
        $this->assertDatabaseHas('drafts', ['id' => $draft->id]);
    }
}
