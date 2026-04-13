<?php

namespace Database\Factories;

use App\Enums\ThoughtLinkType;
use App\Models\Thought;
use App\Models\ThoughtLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThoughtLink>
 */
class ThoughtLinkFactory extends Factory
{
    protected $model = ThoughtLink::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'from_thought_id' => Thought::factory(),
            'to_thought_id' => Thought::factory(),
            'link_type' => ThoughtLinkType::RelatesTo->value,
            'note' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ThoughtLink $link): void {
            Thought::query()->whereKey($link->from_thought_id)->update(['user_id' => $link->user_id]);
            Thought::query()->whereKey($link->to_thought_id)->update(['user_id' => $link->user_id]);
        });
    }
}
