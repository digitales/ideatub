<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ThoughtFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content'   => fake()->sentence(10),
            'metadata'  => null,
            'embedding' => null,
        ];
    }
}
