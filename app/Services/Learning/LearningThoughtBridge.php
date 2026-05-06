<?php

namespace App\Services\Learning;

use App\Models\LearningLesson;
use App\Models\Thought;
use App\Services\ThoughtCaptureService;
use InvalidArgumentException;

class LearningThoughtBridge
{
    private const ARTIFACT_TYPES = ['takeaway', 'confusion', 'lesson_summary'];

    public function __construct(
        private readonly ThoughtCaptureService $captureService,
    ) {}

    /**
     * Capture a durable learning artifact into thoughts.
     *
     * @param  array{artifact_type: string, content: string}  $payload
     */
    public function capture(LearningLesson $lesson, int $userId, array $payload): Thought
    {
        $artifactType = (string) ($payload['artifact_type'] ?? '');
        if (! in_array($artifactType, self::ARTIFACT_TYPES, true)) {
            throw new InvalidArgumentException('Invalid artifact_type.');
        }

        $content = trim((string) ($payload['content'] ?? ''));
        if ($content === '') {
            throw new InvalidArgumentException('Content is required.');
        }

        $lesson->loadMissing('learningProject');

        $project = $lesson->learningProject;
        if ($project === null) {
            throw new InvalidArgumentException('Lesson is missing its learning project.');
        }

        $lessonUrl = route('learn.lessons.show', [
            'learning_project' => $project,
            'slug' => $lesson->slug,
        ]);

        $result = $this->captureService->create([
            'content' => $content,
            'user_id' => $userId,
            'source' => 'learning',
            'source_metadata' => [
                'learning_project_id' => $project->id,
                'learning_project_slug' => $project->slug,
                'lesson_slug' => $lesson->slug,
                'artifact_type' => $artifactType,
                'lesson_url' => $lessonUrl,
            ],
            'no_chunking' => true,
        ]);

        $thought = $result['thought'] ?? null;
        if (! $thought instanceof Thought) {
            throw new InvalidArgumentException('Thought capture did not return a thought.');
        }

        return $thought;
    }
}
