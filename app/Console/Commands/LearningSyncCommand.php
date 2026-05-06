<?php

namespace App\Console\Commands;

use App\Models\LearningProject;
use App\Services\Learning\LearningSyncService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class LearningSyncCommand extends Command
{
    protected $signature = 'learning:sync {project : UUID of learning project} {--user= : owning user id}';

    protected $description = 'Sync learning project markdown content from disk into the database.';

    public function handle(LearningSyncService $learningSyncService): int
    {
        $userOption = $this->option('user');
        if ($userOption === null || $userOption === '') {
            $this->error('The --user option is required.');

            return self::FAILURE;
        }

        if (! is_string($userOption) || trim($userOption) === '' || ! ctype_digit(trim($userOption))) {
            $this->error('The --user option must be a numeric user id.');

            return self::FAILURE;
        }

        $userId = (int) trim($userOption);

        $projectId = $this->argument('project');
        if (! is_string($projectId) || trim($projectId) === '') {
            $this->error('Learning project id must be a non-empty UUID.');

            return self::FAILURE;
        }

        $project = LearningProject::query()->find(trim($projectId));
        if ($project === null) {
            $this->error('Learning project not found.');

            return self::FAILURE;
        }

        if ((int) $project->user_id !== $userId) {
            $this->error('Learning project does not belong to the specified user.');

            return self::FAILURE;
        }

        try {
            $counts = $learningSyncService->sync($project);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Synced research: '.$counts['research']);
        $this->info('Synced lessons: '.$counts['lessons']);

        return self::SUCCESS;
    }
}
