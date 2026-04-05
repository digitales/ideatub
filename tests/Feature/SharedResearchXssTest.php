<?php

namespace Tests\Feature;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedResearchXssTest extends TestCase
{
    use RefreshDatabase;

    public function test_script_tag_in_shared_research_is_stripped(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => '<script>alert("xss")</script>',
            'source' => 'web',
        ]);
        $share = ResearchShare::factory()->create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
        ]);

        $response = $this->get('/r/' . $share->token);

        $response->assertStatus(200);
        $response->assertDontSee('<script>alert("xss")</script>', false);
        $response->assertDontSee('<script>', false);
    }

    public function test_javascript_href_in_shared_research_is_stripped(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => '[click me](javascript:alert(1))',
            'source' => 'web',
        ]);
        $share = ResearchShare::factory()->create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
        ]);

        $response = $this->get('/r/' . $share->token);

        $response->assertStatus(200);
        $response->assertDontSee('javascript:alert', false);
    }
}
