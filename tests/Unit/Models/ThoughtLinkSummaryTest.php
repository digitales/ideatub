<?php

namespace Tests\Unit\Models;

use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtLinkSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_and_resolves_relations_for_queued_editorial_newsletter_link(): void
    {
        $user = User::factory()->create();

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'metadata' => ['type' => 'note'],
        ]);

        $researchThought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research'],
        ]);

        $url = 'https://example.com/article';

        $summary = ThoughtLinkSummary::query()->create([
            'user_id' => $user->id,
            'source_thought_id' => $emailThought->id,
            'parent_research_thought_id' => $researchThought->id,
            'source_type' => 'email_newsletter',
            'original_url' => $url,
            'normalized_url' => $url,
            'normalized_url_hash' => sha1($url),
            'newsletter_section_label' => 'Headlines',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'queued',
        ]);

        $this->assertTrue($summary->sourceThought->is($emailThought));
        $this->assertTrue($summary->parentResearchThought->is($researchThought));
        $this->assertTrue($emailThought->sourceLinkSummaries->contains($summary));
        $this->assertTrue($researchThought->researchLinkSummaries->contains($summary));
        $this->assertSame('queued', $summary->processing_status);
        $this->assertSame('Headlines', $summary->newsletter_section_label);
    }

    public function test_factory_defaults_keep_summary_user_aligned_with_source_thought_user(): void
    {
        $summary = ThoughtLinkSummary::factory()->create();

        $this->assertSame($summary->sourceThought->user_id, $summary->user_id);
    }

    public function test_unique_constraint_rejects_duplicate_source_url_without_research_parent(): void
    {
        $user = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
        ]);

        $attributes = [
            'user_id' => $user->id,
            'source_thought_id' => $emailThought->id,
            'parent_research_thought_id' => null,
            'source_type' => 'email_newsletter',
            'original_url' => 'https://example.com/article',
            'normalized_url' => 'https://example.com/article',
            'normalized_url_hash' => sha1('https://example.com/article'),
            'classification' => 'editorial',
            'processing_status' => 'queued',
        ];

        ThoughtLinkSummary::query()->create($attributes);

        $this->expectException(QueryException::class);

        ThoughtLinkSummary::query()->create($attributes);
    }

    public function test_unique_constraint_rejects_duplicate_source_url_with_same_research_parent(): void
    {
        $user = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
        ]);
        $researchThought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research'],
        ]);

        $attributes = [
            'user_id' => $user->id,
            'source_thought_id' => $emailThought->id,
            'parent_research_thought_id' => $researchThought->id,
            'source_type' => 'email_newsletter',
            'original_url' => 'https://example.com/article',
            'normalized_url' => 'https://example.com/article',
            'normalized_url_hash' => sha1('https://example.com/article'),
            'classification' => 'editorial',
            'processing_status' => 'queued',
        ];

        ThoughtLinkSummary::query()->create($attributes);

        $this->expectException(QueryException::class);

        ThoughtLinkSummary::query()->create($attributes);
    }
}
