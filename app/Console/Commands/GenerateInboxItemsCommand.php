<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Inbox\InboxGenerationService;
use Illuminate\Console\Command;

class GenerateInboxItemsCommand extends Command
{
    protected $signature = 'inbox:generate';

    protected $description = 'Generate inbox items for all users using configured generators.';

    public function handle(InboxGenerationService $inboxGeneration): int
    {
        $totalUsers = 0;
        $totalCreated = 0;

        User::query()->orderBy('id')->chunkById(100, function ($users) use ($inboxGeneration, &$totalUsers, &$totalCreated): void {
            foreach ($users as $user) {
                $totalUsers++;
                $totalCreated += $inboxGeneration->generateForUser($user);
            }
        });

        $this->info(sprintf('Processed %d user(s), created %d inbox item(s).', $totalUsers, $totalCreated));

        return self::SUCCESS;
    }
}
