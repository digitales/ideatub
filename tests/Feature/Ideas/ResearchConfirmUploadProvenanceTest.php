<?php

namespace Tests\Feature\Ideas;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchConfirmUploadProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_requires_provenance_ack_for_upload_thoughts(): void
    {
        $user = User::factory()->create();
        $thought = Thought::create([
            'user_id' => $user->id,
            'content' => 'imported content',
            'source' => 'upload',
            'source_metadata' => ['provenance' => 'upload', 'untrusted_origin' => true],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('ideas.research', $thought));

        $response->assertStatus(409);
        $response->assertJson(['error' => 'provenance_ack_required']);
    }

    public function test_research_proceeds_with_provenance_ack(): void
    {
        $user = User::factory()->create();
        $thought = Thought::create([
            'user_id' => $user->id,
            'content' => 'imported content',
            'source' => 'upload',
            'source_metadata' => ['provenance' => 'upload'],
        ]);

        $response = $this->actingAs($user)
            ->post(route('ideas.research', $thought), ['provenance_ack' => '1']);

        $response->assertStatus(302);
    }

    public function test_research_on_non_upload_thoughts_does_not_require_ack(): void
    {
        $user = User::factory()->create();
        $thought = Thought::create([
            'user_id' => $user->id,
            'content' => 'typed content',
            'source' => 'web',
        ]);

        $response = $this->actingAs($user)
            ->post(route('ideas.research', $thought));

        $response->assertStatus(302);
    }
}
