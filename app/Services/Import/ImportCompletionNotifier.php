<?php

namespace App\Services\Import;

use App\Mail\ImportCompletedMail;
use App\Models\ImportBatch;
use App\Models\InboxItem;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ImportCompletionNotifier
{
    public function notify(ImportBatch $batch): void
    {
        if ($batch->completion_notified_at !== null) {
            return;
        }

        DB::transaction(function () use ($batch): void {
            $batch->refresh();
            if ($batch->completion_notified_at !== null) {
                return;
            }

            $this->createInboxItem($batch);
            $this->sendMail($batch);

            $batch->forceFill(['completion_notified_at' => now()])->save();
        });
    }

    private function createInboxItem(ImportBatch $batch): void
    {
        InboxItem::query()->updateOrCreate(
            [
                'user_id' => $batch->user_id,
                'dedupe_key' => 'import:'.$batch->id,
            ],
            [
                'user_id' => $batch->user_id,
                'generator_type' => 'import_completed',
                'title' => $this->title($batch),
                'body' => $this->body($batch),
                'status' => 'pending',
                'generated_at' => now(),
                'source_data' => [
                    'batch_id' => $batch->id,
                    'project_id' => $batch->project_id,
                    'file_count' => $batch->file_count,
                    'processed_count' => $batch->processed_count,
                    'failed_count' => $batch->failed_count,
                    'skipped_count' => $batch->skipped_count,
                ],
            ]
        );
    }

    private function sendMail(ImportBatch $batch): void
    {
        $user = $batch->user;
        if ($user === null) {
            return;
        }

        $pref = UserPreference::query()
            ->where('user_id', $user->id)
            ->where('key', 'email_on_import_completion')
            ->value('value');

        if ($pref !== null && (string) $pref === 'false') {
            return;
        }

        Mail::to($user->email)->queue(new ImportCompletedMail($batch));
    }

    private function title(ImportBatch $batch): string
    {
        $name = $batch->root_folder_name ?? 'files';

        return "Imported {$name} — {$batch->processed_count} thoughts".($batch->failed_count > 0 ? ", {$batch->failed_count} failed" : '');
    }

    private function body(ImportBatch $batch): string
    {
        $lines = ["Imported **{$batch->file_count}** files.", "- {$batch->processed_count} thoughts created"];
        if ($batch->failed_count > 0) {
            $lines[] = "- {$batch->failed_count} failed";
        }
        if ($batch->skipped_count > 0) {
            $lines[] = "- {$batch->skipped_count} skipped as duplicates";
        }

        return implode("\n", $lines);
    }
}
