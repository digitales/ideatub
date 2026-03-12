<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_thoughts_search_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/thoughts/search?query=test');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'unauthorized']);
    }

    public function test_thoughts_recent_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/thoughts/recent');

        $response->assertStatus(401);
    }

    public function test_thoughts_stats_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/thoughts/stats');

        $response->assertStatus(401);
    }

    public function test_thoughts_store_without_auth_returns_401(): void
    {
        $response = $this->postJson('/api/thoughts', ['content' => 'A thought']);

        $response->assertStatus(401);
    }

    public function test_thoughts_search_with_invalid_bearer_returns_401(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson('/api/thoughts/search?query=test');

        $response->assertStatus(401);
    }
}
