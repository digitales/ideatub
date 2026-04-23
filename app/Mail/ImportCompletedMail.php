<?php

namespace App\Mail;

use App\Models\ImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ImportCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public ImportBatch $batch) {}

    public function envelope(): Envelope
    {
        $b = $this->batch;
        if ($b->isMicrositeImport()) {
            $n = (int) $b->processed_count;
            $label = $b->root_folder_name ?? 'Your files';
            if ($b->failed_count > 0) {
                $subject = "IdeaTub: Research site ({$n} pages) — {$label} ({$b->failed_count} failed)";
            } else {
                $subject = "IdeaTub: Research site ({$n} pages) — {$label} imported";
            }
        } else {
            $title = $b->root_folder_name ?? 'Your files';
            $subject = "IdeaTub: {$title} imported — {$b->processed_count} thoughts, {$b->failed_count} failed";
        }

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.import-completed',
            text: 'mail.text.import-completed',
            with: [
                'batch' => $this->batch,
                'project' => $this->batch->project,
                'projectUrl' => $this->batch->project_id
                    ? route('projects.show', $this->batch->project_id)
                    : null,
                'importUrl' => route('imports.show', $this->batch->id),
                'failedFiles' => $this->batch->files()->where('status', 'failed')->get(),
                'isMicrosite' => $this->batch->isMicrositeImport(),
            ],
        );
    }
}
