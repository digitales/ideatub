<?php

namespace App\Jobs;

use App\Http\Controllers\JiraSettingsController;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\JiraSyncService;
use App\Services\OpenRouterService;
use Carbon\Carbon;
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

    /** Allow long runs: Jira fetch (many API calls) + per-event embedding. Default worker timeout is often 60s. */
    public int $timeout = 600;

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

        $existing = UserPreference::get($user, JiraSettingsController::getSyncStatusKey());
        $since = null;
        if ($this->days === 0 && is_array($existing) && ($existing['status'] ?? '') === 'completed' && ! empty($existing['started_at'] ?? '')) {
            try {
                $since = Carbon::parse($existing['started_at']);
            } catch (\Throwable) {
                // ignore parse errors; fall back to scheduled_sync_days
            }
        }

        $this->setSyncStatusRunning($user);

        $days = $this->days > 0 ? $this->days : (int) config('services.jira.default_days', 14);
        $scheduledDays = (int) config('services.jira.scheduled_sync_days', 1);
        $newCount = 0;

        try {
            if ($this->days > 0) {
                $events = $jiraSync->fetchEvents($user, $days, null);
            } elseif ($since !== null) {
                $events = $jiraSync->fetchEvents($user, 0, $since);
            } else {
                $events = $jiraSync->fetchEvents($user, $scheduledDays, null);
            }

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

            $message = $newCount > 0
                ? "Synced {$newCount} new event(s)."
                : ($since !== null ? 'No new activity since last sync.' : 'No new activity in the last ' . ($this->days > 0 ? $days : $scheduledDays) . ' days.');
            $this->setSyncStatus($user, 'completed', $message);
        } catch (Throwable $e) {
            $this->setSyncStatus($user, 'failed', $e->getMessage());
            throw $e;
        }
    }

    private function setSyncStatusRunning(?User $user): void
    {
        if ($user === null) {
            return;
        }
        UserPreference::set($user, JiraSettingsController::getSyncStatusKey(), [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'completed_at' => null,
            'message' => null,
        ]);
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
