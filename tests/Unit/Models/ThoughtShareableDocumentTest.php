<?php

namespace Tests\Unit\Models;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ThoughtShareableDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_root_web_source_is_shareable(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'research'],
        ]);

        $this->assertTrue($thought->isShareableDocumentRoot());
    }

    public function test_plan_and_plans_aliases_are_shareable(): void
    {
        $user = User::factory()->create();

        $plan = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'plan'],
        ]);
        $this->assertTrue($plan->isShareableDocumentRoot());

        $plans = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'plans'],
        ]);
        $this->assertTrue($plans->isShareableDocumentRoot());
    }

    public function test_meeting_and_meetings_aliases_are_shareable(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $meeting = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'meeting'],
        ]);
        $this->assertTrue($meeting->isShareableDocumentRoot());

        $meetings = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'meetings'],
        ]);
        $this->assertTrue($meetings->isShareableDocumentRoot());
    }

    public function test_mcp_extra_doc_types_are_shareable(): void
    {
        $user = User::factory()->create();
        foreach (['decision', 'dev', 'support', 'spec'] as $type) {
            $thought = Thought::factory()->create([
                'user_id' => $user->id,
                'parent_id' => null,
                'source' => 'web',
                'metadata' => ['type' => $type],
            ]);
            $this->assertTrue($thought->isShareableDocumentRoot(), "Expected shareable for type {$type}");
        }
    }

    public function test_child_thought_is_not_shareable_even_with_research_type(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'research'],
        ]);
        $child = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'source' => 'web',
            'metadata' => ['type' => 'research'],
        ]);

        $this->assertFalse($child->isShareableDocumentRoot());
    }

    public function test_video_type_is_not_shareable(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'video'],
        ]);

        $this->assertFalse($thought->isShareableDocumentRoot());
    }

    public function test_email_source_is_not_shareable(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'is_visible_in_stream' => true,
            'metadata' => ['type' => 'research'],
        ]);

        $this->assertFalse($thought->isShareableDocumentRoot());
    }

    public function test_jira_source_is_not_shareable(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'jira',
            'metadata' => ['type' => 'research'],
        ]);

        $this->assertFalse($thought->isShareableDocumentRoot());
    }

    public function test_missing_or_empty_metadata_type_is_not_shareable(): void
    {
        $user = User::factory()->create();

        $nullMeta = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => null,
        ]);
        $this->assertFalse($nullMeta->isShareableDocumentRoot());

        $noType = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['tags' => ['x']],
        ]);
        $this->assertFalse($noType->isShareableDocumentRoot());
    }

    public function test_mcp_capture_source_without_metadata_type_is_shareable(): void
    {
        $user = User::factory()->create();

        foreach (['plan', 'research', 'meeting', 'decision', 'dev', 'support', 'spec'] as $docType) {
            $thought = Thought::factory()->create([
                'user_id' => $user->id,
                'parent_id' => null,
                'source' => $docType,
                'metadata' => ['tags' => ["{$docType}:example-slug"]],
            ]);
            $this->assertTrue(
                $thought->isShareableDocumentRoot(),
                "Expected shareable for MCP source {$docType} without metadata.type"
            );
        }
    }

    public function test_non_shareable_metadata_type_is_not_overridden_by_plan_source(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'plan',
            'metadata' => ['type' => 'idea'],
        ]);

        $this->assertFalse($thought->isShareableDocumentRoot());
    }
}
