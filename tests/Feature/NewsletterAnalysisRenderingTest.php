<?php

namespace Tests\Feature;

use App\Models\NewsletterAnalysis;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NewsletterAnalysisRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function makeResearchThought(User $user): Thought
    {
        return Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'research',
            'metadata' => ['type' => 'research'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function research_show_renders_analysis_sections_when_completed(): void
    {
        $user = User::factory()->create();
        $researchThought = $this->makeResearchThought($user);
        $emailThought = Thought::factory()->create(['user_id' => $user->id]);

        NewsletterAnalysis::query()->create([
            'research_thought_id' => $researchThought->id,
            'source_thought_id' => $emailThought->id,
            'stored_email_type' => 'imported_email',
            'stored_email_id' => 1,
            'status' => 'completed',
            'summary' => 'This is the newsletter summary.',
            'key_points' => ['Point one', 'Point two'],
            'positives_mentioned' => ['Positive thing'],
            'negatives_mentioned' => ['Negative thing'],
            'highlights' => ['Notable highlight'],
            'quality_notes' => null,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('idea.research.show', $researchThought));

        $response->assertOk();
        $response->assertSee('This is the newsletter summary.');
        $response->assertSee('Point one');
        $response->assertSee('Point two');
        $response->assertSee('Positive thing');
        $response->assertSee('Negative thing');
        $response->assertSee('Notable highlight');
    }

    #[Test]
    public function research_show_renders_pending_note_when_analysis_is_processing(): void
    {
        $user = User::factory()->create();
        $researchThought = $this->makeResearchThought($user);
        $emailThought = Thought::factory()->create(['user_id' => $user->id]);

        NewsletterAnalysis::query()->create([
            'research_thought_id' => $researchThought->id,
            'source_thought_id' => $emailThought->id,
            'stored_email_type' => 'imported_email',
            'stored_email_id' => 1,
            'status' => 'processing',
        ]);

        $response = $this->actingAs($user)
            ->get(route('idea.research.show', $researchThought));

        $response->assertOk();
        $response->assertSee('Newsletter analysis processing');
    }

    #[Test]
    public function research_show_renders_failure_note_when_analysis_failed(): void
    {
        $user = User::factory()->create();
        $researchThought = $this->makeResearchThought($user);
        $emailThought = Thought::factory()->create(['user_id' => $user->id]);

        NewsletterAnalysis::query()->create([
            'research_thought_id' => $researchThought->id,
            'source_thought_id' => $emailThought->id,
            'stored_email_type' => 'imported_email',
            'stored_email_id' => 1,
            'status' => 'failed',
            'failure_reason' => 'body_too_short',
        ]);

        $response = $this->actingAs($user)
            ->get(route('idea.research.show', $researchThought));

        $response->assertOk();
        $response->assertSee('Newsletter analysis could not be completed');
    }

    #[Test]
    public function research_show_renders_nothing_for_analysis_when_no_record_exists(): void
    {
        $user = User::factory()->create();
        $researchThought = $this->makeResearchThought($user);

        $response = $this->actingAs($user)
            ->get(route('idea.research.show', $researchThought));

        $response->assertOk();
        $response->assertDontSee('Newsletter analysis processing');
        $response->assertDontSee('Newsletter analysis could not be completed');
    }
}
