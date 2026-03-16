<?php

namespace App\Jobs;

use App\Http\Controllers\JiraSettingsController;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\JiraSyncService;
use App\Services\OpenRouterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncUserJiraActivity implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $userId,
        private readonly int $days = 0
    ) {}

    /**
     * Execute the job.
     */
    public function handle(JiraSyncService $jiraSync, OpenRouterService $openRouter): void
    {
        $user = User::find($this->userId);
        if ($user === null || $user->jiraCredential === null) {
            $this->setSyncStatus($user, 'completed', null);

            return;
        }

        $days = $this->days > 0 ? $this->days : (int) config('services.jira.default_days', 14);
        $newCount = 0;

        try {
            $events = $jiraSync->fetchEvents($user, $days);

            foreach ($events as $event) {
                $eventId = $event['source_metadata']['jira_event_id'] ?? null;
                if ($eventId === null) {
                    continue;
                }
                if (Thought::where('user_id', $user->id)
                    ->where('source', 'jira')
                    ->where('source_metadata->jira_event_id', $eventId)
                    ->exists()) {
                    continue;
                }

                $content = $event['content'];
                $metadata = $event['metadata'];
                $sourceMetadata = $event['source_metadata'];

                $embedding = $openRouter->embed($content);
                Thought::create([
                    'content' => $content,
                    'embedding' => $embedding,
                    'metadata' => $metadata,
                    'user_id' => $user->id,
                    'source' => 'jira',
                    'source_metadata' => $sourceMetadata,
                ]);
                $newCount++;
            }

            $this->setSyncStatus($user, 'completed', $newCount > 0 ? "Synced {$newCount} new event(s)." : 'No new activity in the last ' . $days . ' days.');
        } catch (Throwable $e) {
            $this->setSyncStatus($user, 'failed', $e->getMessage());
            throw $e;
        }
    }

    private function setSyncStatus(?User $user, string $status, ?string $message): void
    {
        if ($user === null) {
            return;
        }
        $existing = UserPreference::get($user, JiraSettingsController::getSyncStatusKey());
        $startedAt = is_array($existing) ? ($existing['started_at'] ?? null) : null;
        UserPreference::set($user, JiraSettingsController::getSyncStatusKey(), [
            'status' => $status,
            'started_at' => $startedAt,
            'completed_at' => now()->toIso8601String(),
            'message' => $message,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $user = User::find($this->userId);
        if ($user !== null && $exception !== null) {
            $this->setSyncStatus($user, 'failed', $exception->getMessage());
        }
    }
}
