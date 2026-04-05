<?php

namespace Database\Factories;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResearchShareFactory extends Factory
{
    protected $model = ResearchShare::class;

    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'thought_id'    => Thought::factory(),
            'token'         => ResearchShare::generateToken(),
            'password_hash' => null,
            'expires_at'    => null,
        ];
    }
}
