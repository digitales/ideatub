<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\Thought;
use Illuminate\Support\Facades\Route;
use Throwable;

final class ProjectPinnedContextPayload
{
    /**
     * @return array{
     *     thought_id: string,
     *     content: string,
     *     url: string|null,
     * }|null
     */
    public function forThought(?Thought $thought): ?array
    {
        if ($thought === null) {
            return null;
        }

        return [
            'thought_id' => (string) $thought->id,
            'content' => (string) $thought->content,
            'url' => $this->thoughtUrl($thought),
        ];
    }

    /**
     * @return array{
     *     thought_id: string,
     *     content: string,
     *     url: string|null,
     * }|null
     */
    public function forProjectScope(int $userId, string $scopeKey): ?array
    {
        $project = Project::query()
            ->where('user_id', $userId)
            ->whereKey($scopeKey)
            ->with('contextThought')
            ->first();

        if ($project === null) {
            return null;
        }

        return $this->forThought($project->contextThought);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mergeIntoWorkingMemoryPayload(array $payload, int $userId): array
    {
        if (($payload['scope_type'] ?? null) !== 'project') {
            return $payload;
        }

        $payload['pinned_context'] = $this->forProjectScope(
            $userId,
            (string) ($payload['scope_key'] ?? '')
        );

        return $payload;
    }

    private function thoughtUrl(Thought $thought): ?string
    {
        try {
            return $thought->ideaTubViewUrl();
        } catch (Throwable) {
            try {
                if (Route::has('thoughts.show')) {
                    return route('thoughts.show', ['thought' => $thought->id]);
                }
            } catch (Throwable) {
                // omit url
            }
        }

        return null;
    }
}
