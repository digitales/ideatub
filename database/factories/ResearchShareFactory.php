<?php

namespace Database\Factories;

use App\Models\ResearchShare;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResearchShareFactory extends Factory
{
    protected $model = ResearchShare::class;

    public function definition(): array
    {
        return [
            'user_id'       => null,
            'thought_id'    => null,
            'token'         => ResearchShare::generateToken(),
            'password_hash' => null,
            'expires_at'    => null,
        ];
    }
}
