<?php

namespace App\Services\Projects;

use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;

final class ProjectSettingsService
{
    /**
     * @param  array{working_memory_auto_update?: bool}  $attributes
     */
    public function updateForUser(int $userId, Project $project, array $attributes): Project
    {
        if ($project->user_id !== $userId) {
            throw new AuthorizationException('You do not own this project.');
        }

        $updates = [];
        if (array_key_exists('working_memory_auto_update', $attributes)) {
            $updates['working_memory_auto_update'] = (bool) $attributes['working_memory_auto_update'];
        }

        if ($updates !== []) {
            $project->update($updates);
        }

        return $project->fresh();
    }
}
