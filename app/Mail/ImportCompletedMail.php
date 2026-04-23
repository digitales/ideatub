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
        $title = $this->batch->root_folder_name ?? 'Your files';

        return new Envelope(
            subject: "IdeaTub: {$title} imported — {$this->batch->processed_count} thoughts, {$this->batch->failed_count} failed",
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
            ],
        );
    }
}
