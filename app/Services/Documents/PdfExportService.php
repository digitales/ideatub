<?php

namespace App\Services\Documents;

use App\Models\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;

class PdfExportService
{
    public function export(Application $application, string $document): string
    {
        if (! in_array($document, ['cv', 'cover_letter'], true)) {
            throw new \InvalidArgumentException("Unknown document type: {$document}");
        }

        $markdown = $document === 'cv' ? $application->cv_markdown : $application->cover_letter_markdown;
        $html = $this->markdownToHtml((string) $markdown);
        $pdfContents = $this->renderPdf($html);

        $path = "job_pipeline/{$application->id}/{$document}.pdf";
        Storage::disk('local')->put($path, $pdfContents);

        if ($document === 'cv') {
            $application->update(['cv_pdf_path' => $path, 'cv_exported_at' => now()]);
        } else {
            $application->update(['cover_letter_pdf_path' => $path, 'cover_letter_exported_at' => now()]);
        }

        return $path;
    }

    protected function renderPdf(string $html): string
    {
        return Browsershot::html($html)->pdf();
    }

    private function markdownToHtml(string $markdown): string
    {
        $body = Str::markdown($markdown);

        return '<html><head><style>'.CvStyle::css().'</style></head><body>'.$body.'</body></html>';
    }
}
