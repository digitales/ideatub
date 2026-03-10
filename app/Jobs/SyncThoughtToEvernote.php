<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\EvernoteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncThoughtToEvernote implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying after a failure.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly string $thoughtId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EvernoteService $evernoteService): void
    {
        if (! $evernoteService->isConfigured()) {
            return;
        }

        $thought = Thought::find($this->thoughtId);
        if (! $thought) {
            Log::warning('SyncThoughtToEvernote: thought not found', ['thought_id' => $this->thoughtId]);

            return;
        }

        try {
            if ($thought->evernote_note_guid === null || $thought->evernote_note_guid === '') {
                $guid = $evernoteService->createNote($thought);
                if ($guid !== null && $guid !== '') {
                    $thought->evernote_note_guid = $guid;
                    Thought::withoutEvents(fn () => $thought->save());
                }
            } else {
                $evernoteService->updateNote($thought);
            }
        } catch (\Throwable $e) {
            Log::error('SyncThoughtToEvernote exception', [
                'thought_id' => $this->thoughtId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure: log and allow Laravel to retry.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('SyncThoughtToEvernote job failed', [
            'thought_id' => $this->thoughtId,
            'exception' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);
    }
}
