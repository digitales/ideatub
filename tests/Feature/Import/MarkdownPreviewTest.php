<?php

namespace Tests\Feature\Import;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkdownPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_returns_rendered_html(): void
    {
        config()->set('features.file_upload', true);
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('imports.preview-markdown'), [
                'content' => '# Hello World',
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['html']);
        $this->assertStringContainsString('<h1>', $response->json('html'));
        $this->assertStringContainsString('Hello World', $response->json('html'));
    }

    public function test_preview_strips_yaml_front_matter(): void
    {
        config()->set('features.file_upload', true);
        $user = User::factory()->create();

        $content = "---\ntitle: My Doc\n---\n# Actual Content";

        $response = $this->actingAs($user)
            ->postJson(route('imports.preview-markdown'), [
                'content' => $content,
            ]);

        $response->assertOk();
        $this->assertStringContainsString('Actual Content', $response->json('html'));
        $this->assertStringNotContainsString('title: My Doc', $response->json('html'));
    }

    public function test_preview_rejects_empty_content(): void
    {
        config()->set('features.file_upload', true);
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('imports.preview-markdown'), [
                'content' => '',
            ]);

        $response->assertUnprocessable();
    }

    public function test_preview_requires_authentication(): void
    {
        config()->set('features.file_upload', true);

        $response = $this->postJson(route('imports.preview-markdown'), [
            'content' => '# Test',
        ]);

        $response->assertUnauthorized();
    }

    public function test_preview_returns_404_when_feature_disabled(): void
    {
        config()->set('features.file_upload', false);
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('imports.preview-markdown'), [
                'content' => '# Test',
            ]);

        $response->assertNotFound();
    }
}
