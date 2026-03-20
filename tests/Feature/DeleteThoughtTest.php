<?php

namespace Tests\Feature;

use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteThoughtTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_delete_thought_with_no_comments(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->deleteJson(route('ideas.destroy', $thought));

        $response->assertNoContent();
        $this->assertDatabaseMissing('thoughts', ['id' => $thought->id]);
    }

    public function test_owner_cannot_delete_thought_with_comments(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'embedding' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $thought->id,
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->deleteJson(route('ideas.destroy', $thought));

        $response->assertUnprocessable();
        $response->assertJson(['message' => 'This thought has comments. Remove them first.']);
        $this->assertDatabaseHas('thoughts', ['id' => $thought->id]);
    }

    public function test_other_user_cannot_delete_thought(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'embedding' => null,
        ]);

        $response = $this->actingAs($other)->deleteJson(route('ideas.destroy', $thought));

        $response->assertForbidden();
        $this->assertDatabaseHas('thoughts', ['id' => $thought->id]);
    }

    public function test_guest_cannot_delete_thought(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'embedding' => null,
        ]);

        $response = $this->deleteJson(route('ideas.destroy', $thought));

        $response->assertUnauthorized();
        $this->assertDatabaseHas('thoughts', ['id' => $thought->id]);
    }

    public function test_delete_returns_404_for_missing_thought(): void
    {
        $owner = User::factory()->create();
        $uuid = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($owner)->deleteJson(route('ideas.destroy', ['thought' => $uuid]));

        $response->assertNotFound();
    }

    public function test_deleting_email_backed_thought_clears_imported_email_link(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'source' => 'email',
            'source_metadata' => ['provider_message_id' => 'msg-123'],
            'embedding' => null,
        ]);

        $imported = ImportedEmail::create([
            'user_id' => $owner->id,
            'mail_account_id' => MailAccount::factory()->create(['user_id' => $owner->id])->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-123',
            'direction' => 'sent',
            'processing_status' => 'imported',
            'thought_id' => $thought->id,
        ]);

        $this->actingAs($owner)->deleteJson(route('ideas.destroy', $thought))->assertNoContent();

        $this->assertDatabaseHas('imported_emails', [
            'id' => $imported->id,
            'thought_id' => null,
        ]);
        $this->assertDatabaseMissing('imported_emails', [
            'id' => $imported->id,
            'thought_deleted_at' => null,
        ]);
    }
}
