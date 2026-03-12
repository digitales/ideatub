<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdeaPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_idea_page_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertStatus(200);
        $response->assertSee('IdeaTub');
        $response->assertSee('What are you thinking?');
        $response->assertSee('Store thought');
        $response->assertSee('Example Prompts');
        $response->assertSee('Help');
        $response->assertSee('Find a memory');
    }

    public function test_idea_page_redirects_guests(): void
    {
        $response = $this->get(route('idea.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_idea_page_shows_stored_thoughts(): void
    {
        $user = User::factory()->create();
        $thought = \App\Models\Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'This is a test thought about semantic search',
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertStatus(200);
        $response->assertSee('This is a test thought about semantic search');
        $response->assertSee('Recent thoughts');
    }

    public function test_idea_page_shows_search_results(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('pgvector')->andReturn($fakeEmbedding);
        });

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'pgvector is great for embeddings',
            'embedding' => $fakeEmbedding,
        ]);

        $response = $this->actingAs($user)->get(route('idea.index', ['q' => 'pgvector']));

        $response->assertStatus(200);
        $response->assertSee('pgvector is great for embeddings');
    }

    public function test_web_created_thought_has_source_web(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->actingAs($user)->post(route('thoughts.store'), [
            'content' => 'A thought from the web',
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('idea.index'));
        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('web', $thought->source);
    }

    public function test_example_prompts_page_loads_with_prompt_kit_content(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('example-prompts'));

        $response->assertStatus(200);
        $response->assertSee('Example Prompts');
        $response->assertSee('Memory Migration');
        $response->assertSee('Second Brain Migration');
        $response->assertSee('Quick Capture Templates');
        $response->assertSee('The Weekly Review');
        $response->assertSee('Decision: [what was decided]');
        $response->assertSee('promptkit.natebjones.com');
    }
}
