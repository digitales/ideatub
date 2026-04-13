<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectShare>
 */
class ProjectShareFactory extends Factory
{
    protected $model = ProjectShare::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'token' => ProjectShare::generateToken(),
            'password_hash' => null,
            'expires_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ProjectShare $share): void {
            $share->project->update(['user_id' => $share->user_id]);
        });
    }
}
