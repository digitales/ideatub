<?php

namespace Database\Factories;

use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThoughtLinkSummary>
 */
class ThoughtLinkSummaryFactory extends Factory
{
    protected $model = ThoughtLinkSummary::class;

    public function definition(): array
    {
        $url = 'https://example.com/article';

        return [
            'user_id' => User::factory(),
            'source_thought_id' => fn (array $attributes) => Thought::factory()->create([
                'user_id' => $attributes['user_id'],
            ])->id,
            'parent_research_thought_id' => null,
            'source_type' => 'email_newsletter',
            'original_url' => $url,
            'normalized_url' => $url,
            'normalized_url_hash' => sha1($url),
            'classification' => 'editorial',
            'processing_status' => 'queued',
        ];
    }
}
