<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailThoughtStatusDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_thought_research_queued_shows_queued_status_on_index_and_stream(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Email queued for research',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_queued',
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertSee('data-email-research-status="research_queued"', false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertSee('data-email-research-status="research_queued"', false);
    }

    public function test_email_thought_research_partial_shows_partial_status_on_index_and_stream(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Partial research email',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_partial',
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertSee('data-email-research-status="research_partial"', false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertSee('data-email-research-status="research_partial"', false);
    }

    public function test_email_thought_research_completed_with_research_thought_id_shows_status_and_link(): void
    {
        $user = User::factory()->create();
        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Research doc',
            'source' => 'research',
            'metadata' => ['type' => 'research'],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Source email',
            'source' => 'email',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_completed',
                    'research_thought_id' => $research->id,
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertSee('data-email-research-status="research_completed"', false);
        $index->assertSee(route('idea.research.show', $research), false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertSee('data-email-research-status="research_completed"', false);
        $stream->assertSee(route('idea.research.show', $research), false);
    }

    public function test_non_email_thought_does_not_render_email_research_status(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Web thought',
            'source' => 'web',
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_completed',
                    'research_thought_id' => '00000000-0000-0000-0000-000000000001',
                ],
            ],
        ]);

        $index = $this->actingAs($user)->get(route('idea.index'));
        $index->assertStatus(200);
        $index->assertDontSee('data-email-research-status=', false);

        $stream = $this->actingAs($user)->get(route('idea.stream'));
        $stream->assertStatus(200);
        $stream->assertDontSee('data-email-research-status=', false);
    }
}
