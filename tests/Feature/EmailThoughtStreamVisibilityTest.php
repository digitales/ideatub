<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailThoughtStreamVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_hidden_email_absent_from_idea_index_recent(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Visible web thought',
            'parent_id' => null,
            'source' => 'web',
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Hidden email subject line',
            'parent_id' => null,
            'source' => 'email',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee('Visible web thought');
        $response->assertDontSee('Hidden email subject line');
    }

    public function test_hidden_email_absent_from_stream(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Stream visible',
            'parent_id' => null,
            'source' => 'web',
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Stream hidden email',
            'parent_id' => null,
            'source' => 'email',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('Stream visible');
        $response->assertDontSee('Stream hidden email');
    }

    public function test_hidden_email_absent_from_stream_emails(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Visible inbox email',
            'parent_id' => null,
            'source' => 'email',
            'is_visible_in_stream' => true,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Hidden inbox email',
            'parent_id' => null,
            'source' => 'email',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.emails'));

        $response->assertOk();
        $response->assertSee('Visible inbox email');
        $response->assertDontSee('Hidden inbox email');
    }

    public function test_hidden_email_absent_from_idea_index_search(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.02);
        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('findhiddenemailtoken')->andReturn($embedding);
        });

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'findhiddenemailtoken secret body',
            'parent_id' => null,
            'source' => 'email',
            'embedding' => $embedding,
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $response = $this->actingAs($user)->get(route('idea.index', ['q' => 'findhiddenemailtoken']));

        $response->assertOk();
        $response->assertDontSee('findhiddenemailtoken secret body');
    }

    public function test_hidden_email_does_not_trigger_realtime_check(): void
    {
        $user = User::factory()->create();
        $since = now()->subMinutes(5)->toIso8601String();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'New hidden email',
            'parent_id' => null,
            'source' => 'email',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $response = $this->actingAs($user)->getJson(route('api.thoughts.realtime-check', ['since' => $since]));

        $response->assertOk();
        $response->assertJson(['has_new' => false]);
    }

    public function test_hidden_email_still_accessible_on_thought_show(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Direct view hidden email',
            'parent_id' => null,
            'source' => 'email',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $response = $this->actingAs($user)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertSee('Direct view hidden email');
    }

    public function test_visible_non_email_appears_beside_hidden_email_on_index(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Neighbor visible note',
            'parent_id' => null,
            'source' => 'web',
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Suppressed neighbor email',
            'parent_id' => null,
            'source' => 'email',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee('Neighbor visible note');
        $response->assertDontSee('Suppressed neighbor email');
    }

    public function test_hidden_email_stays_filtered_when_email_sender_policy_disabled(): void
    {
        config(['services.email_sender_policy.enabled' => false]);

        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Policy off hidden email',
            'parent_id' => null,
            'source' => 'email',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertDontSee('Policy off hidden email');
    }
}
