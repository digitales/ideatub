<?php

namespace App\Console\Commands;

use App\Models\Thought;
use App\Models\User;
use App\Services\JiraSyncService;
use Illuminate\Console\Command;

class BackfillJiraCommentsCommand extends Command
{
    protected $signature = 'jira:backfill-comments {--user-id= : Backfill only one user id} {--days=30 : Jira lookback window in days} {--dry-run : Show updates without writing}';

    protected $description = 'Backfill existing Jira comment thoughts with full comment text from Jira.';

    public function handle(JiraSyncService $jiraSync): int
    {
        if (! config('services.jira.enabled', true)) {
            $this->comment('Jira integration is disabled. Skipping backfill.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $userId = $this->option('user-id');
        $dryRun = (bool) $this->option('dry-run');

        $users = User::query()->has('jiraCredential');
        if ($userId !== null && $userId !== '') {
            $users->whereKey((int) $userId);
        }

        $users = $users->get();
        if ($users->isEmpty()) {
            $this->info('No eligible users found for Jira backfill.');

            return self::SUCCESS;
        }

        $updatedCount = 0;
        $consideredCount = 0;

        foreach ($users as $user) {
            try {
                $events = $jiraSync->fetchEvents($user, $days);
            } catch (\Throwable $e) {
                $this->warn("User {$user->id}: failed to fetch Jira events ({$e->getMessage()})");
                continue;
            }

            $commentEvents = collect($events)
                ->filter(fn (array $event) => ($event['source_metadata']['jira_event_type'] ?? null) === 'comment')
                ->keyBy(fn (array $event) => $event['source_metadata']['jira_event_id'] ?? '');

            if ($commentEvents->isEmpty()) {
                continue;
            }

            $existing = Thought::query()
                ->where('user_id', $user->id)
                ->where('source', 'jira')
                ->where('source_metadata->jira_event_type', 'comment')
                ->get();

            foreach ($existing as $thought) {
                $eventId = $thought->source_metadata['jira_event_id'] ?? null;
                if (! is_string($eventId) || $eventId === '') {
                    continue;
                }
                $event = $commentEvents->get($eventId);
                if (! is_array($event)) {
                    continue;
                }
                $consideredCount++;
                $newContent = (string) ($event['content'] ?? '');
                if ($newContent === '' || $newContent === $thought->content) {
                    continue;
                }

                $updatedCount++;
                if ($dryRun) {
                    continue;
                }

                $sourceMetadata = is_array($thought->source_metadata) ? $thought->source_metadata : [];
                $eventSourceMetadata = is_array($event['source_metadata'] ?? null) ? $event['source_metadata'] : [];
                $thought->update([
                    'content' => $newContent,
                    'source_metadata' => array_merge($sourceMetadata, $eventSourceMetadata),
                ]);
            }
        }

        if ($dryRun) {
            $this->info("Dry run complete. Would update {$updatedCount} thought(s) from {$consideredCount} candidate comment thought(s).");

            return self::SUCCESS;
        }

        $this->info("Updated {$updatedCount} thought(s) from {$consideredCount} candidate comment thought(s).");

        return self::SUCCESS;
    }
}
