<?php

namespace Database\Factories;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxItem>
 */
class InboxItemFactory extends Factory
{
    protected $model = InboxItem::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'generator_type' => 'weekly_revisit',
            'title' => 'Weekly revisit',
            'body' => 'Review a few older ideas this week.',
            'status' => 'pending',
            'snoozed_until' => null,
            'generated_at' => now(),
            'actioned_at' => null,
            'dedupe_key' => 'weekly-revisit-'.$this->faker->uuid(),
            'source_data' => null,
        ];
    }
}
